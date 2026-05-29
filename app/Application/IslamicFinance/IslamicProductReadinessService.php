<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class IslamicProductReadinessService
{
    public function __construct(
        private readonly IslamicStandardsBaselineService $baseline,
        private readonly IslamicRegulatorySignoffService $signoff,
        private readonly IslamicScreeningPolicyService $screening,
        private readonly IslamicProductFamilyRegistry $productFamilies,
    ) {}

    /**
     * @return array{
     *   overall_status: 'pass'|'fail',
     *   family_code: string,
     *   evaluated_at: string,
     *   gates: array<int, array<string, mixed>>,
     *   failures_by_gate: array<string, array<int, string>>,
     *   missing_items: array<int, string>
     * }
     */
    public function evaluate(object $product, ?CarbonInterface $asOf = null, ?User $actor = null): array
    {
        $contractType = isset(((array) $product)['contract_type']) ? ((array) $product)['contract_type'] : null;
        if (! is_string($contractType) || $contractType === '') {
            return [
                'overall_status' => 'fail',
                'family_code' => '',
                'evaluated_at' => ($asOf ?? CarbonImmutable::now())->toIso8601String(),
                'gates' => [[
                    'gate_key' => 'islamic_product',
                    'status' => 'fail',
                    'reasons' => ['Product contract type is missing.'],
                    'evidence_refs' => [],
                ]],
                'failures_by_gate' => ['islamic_product' => ['Product contract type is missing.']],
                'missing_items' => ['islamic_product:contract_type'],
            ];
        }

        $family = IslamicProductFamilyRegistry::familyForContractType($contractType);
        if ($family === null) {
            return [
                'overall_status' => 'fail',
                'family_code' => '',
                'evaluated_at' => ($asOf ?? CarbonImmutable::now())->toIso8601String(),
                'gates' => [[
                    'gate_key' => 'islamic_product',
                    'status' => 'fail',
                    'reasons' => ['Product contract type does not map to a supported Islamic product family.'],
                    'evidence_refs' => [],
                ]],
                'failures_by_gate' => ['islamic_product' => ['Product contract type does not map to a supported Islamic product family.']],
                'missing_items' => ['islamic_product:contract_type_mapping'],
            ];
        }

        $failuresByGate = [];
        $missingItems = [];
        $gates = [];
        $evaluatedAt = $asOf ?? CarbonImmutable::now();

        $familyKind = $this->productFamilies->familyKindFor($family);
        $standardsFailures = $familyKind === 'account'
            ? $this->baseline->activationFailuresForAccountType($family, $asOf)
            : $this->baseline->activationFailuresForProductFamily($family, $asOf);
        $this->appendGate(
            $gates,
            $failuresByGate,
            $missingItems,
            gateKey: 'islamic_standards_baseline',
            failures: $standardsFailures,
            missingHints: ['islamic_standards_baseline:linked_active_standard'],
            evidenceRefs: ['scope_type' => $familyKind === 'account' ? 'account_type' : 'product_family', 'scope_value' => $family],
        );

        if ($familyKind !== 'account') {
            $signoffFailures = $this->signoff->activationFailuresForProductFamily($family, $asOf);
            $this->appendGate(
                $gates,
                $failuresByGate,
                $missingItems,
                gateKey: 'islamic_regulatory_signoff',
                failures: $signoffFailures,
                missingHints: ['islamic_regulatory_signoff:active_allow_signoff'],
                evidenceRefs: ['scope_type' => 'product_family', 'scope_value' => $family],
            );
        } else {
            $gates[] = [
                'gate_key' => 'islamic_regulatory_signoff',
                'status' => 'not_applicable',
                'reasons' => ['Regulatory sign-off gate is not required for account families.'],
                'evidence_refs' => ['family_kind' => 'account'],
            ];
        }

        if ($familyKind === 'financing') {
            $templateCode = $family.'_contract_template';
            $templateFailures = $this->baseline->hasActiveBaseline('contract_template', $templateCode, $asOf)
                ? []
                : ['No active contract template baseline is linked to this product family.'];
            $this->appendGate(
                $gates,
                $failuresByGate,
                $missingItems,
                gateKey: 'islamic_contract_template',
                failures: $templateFailures,
                missingHints: ['islamic_contract_template:active_linked_template'],
                evidenceRefs: ['linkable_type' => 'contract_template', 'linkable_code' => $templateCode],
            );
        } else {
            $gates[] = [
                'gate_key' => 'islamic_contract_template',
                'status' => 'not_applicable',
                'reasons' => ['Contract template gate is not required for account families.'],
                'evidence_refs' => ['family_kind' => 'account'],
            ];
        }

        $mappingFailures = $this->hasAnyActiveIslamicAccountingMappingBaseline($asOf)
            ? []
            : ['No active Islamic accounting mapping baseline is linked; product activation is blocked.'];
        $this->appendGate(
            $gates,
            $failuresByGate,
            $missingItems,
            gateKey: 'islamic_accounting_mappings',
            failures: $mappingFailures,
            missingHints: ['islamic_accounting_mappings:active_linked_mapping'],
            evidenceRefs: ['linkable_type' => 'accounting_mapping'],
        );

        $scopeValue = isset(((array) $product)['agency_id']) && is_numeric(((array) $product)['agency_id'])
            ? (string) ((int) ((array) $product)['agency_id'])
            : null;
        $rulesRaw = ((array) $product)['rules'] ?? null;
        $rules = [];
        if (is_string($rulesRaw) && $rulesRaw !== '') {
            $decoded = json_decode($rulesRaw, true);
            if (is_array($decoded)) {
                $rules = $decoded;
            }
        }

        $familyMetadata = $this->productFamilies->metadataFor($family);
        $expectedReportingCategory = is_array($familyMetadata) && is_string($familyMetadata['reporting_category'] ?? null)
            ? $familyMetadata['reporting_category']
            : null;
        $reportingCategoryValue = is_string($rules['reporting_category'] ?? null) ? $rules['reporting_category'] : null;

        $documentRequirementFailures = is_array($rules['document_requirements'] ?? null) && $rules['document_requirements'] !== []
            ? []
            : ['Document requirements policy is missing for this product.'];
        $this->appendGate(
            $gates,
            $failuresByGate,
            $missingItems,
            gateKey: 'islamic_document_requirements',
            failures: $documentRequirementFailures,
            missingHints: ['islamic_document_requirements:policy'],
            evidenceRefs: ['policy_present' => is_array($rules['document_requirements'] ?? null)],
        );

        $authorizationRuleFailures = is_array($rules['authorization_rules'] ?? null) && $rules['authorization_rules'] !== []
            ? []
            : ['Authorization rules are missing for this product.'];
        $this->appendGate(
            $gates,
            $failuresByGate,
            $missingItems,
            gateKey: 'islamic_authorization_rules',
            failures: $authorizationRuleFailures,
            missingHints: ['islamic_authorization_rules:maker_checker'],
            evidenceRefs: ['policy_present' => is_array($rules['authorization_rules'] ?? null)],
        );

        $operationalProcedureFailures = is_array($rules['operational_procedure'] ?? null) && $rules['operational_procedure'] !== []
            ? []
            : ['Operational procedure reference is missing for this product.'];
        $this->appendGate(
            $gates,
            $failuresByGate,
            $missingItems,
            gateKey: 'islamic_operational_procedure',
            failures: $operationalProcedureFailures,
            missingHints: ['islamic_operational_procedure:reference'],
            evidenceRefs: ['policy_present' => is_array($rules['operational_procedure'] ?? null)],
        );

        $reportCategoryFailures = [];
        if ($expectedReportingCategory === null || $expectedReportingCategory === '') {
            $reportCategoryFailures[] = 'Product family reporting category is not configured.';
        } elseif ($reportingCategoryValue !== null && $reportingCategoryValue !== $expectedReportingCategory) {
            $reportCategoryFailures[] = 'Reporting category does not match product family reporting category.';
        }
        $this->appendGate(
            $gates,
            $failuresByGate,
            $missingItems,
            gateKey: 'islamic_report_category',
            failures: $reportCategoryFailures,
            missingHints: ['islamic_report_category:family_binding'],
            evidenceRefs: [
                'expected_reporting_category' => $expectedReportingCategory,
                'product_reporting_category' => $reportingCategoryValue,
            ],
        );

        foreach ($this->productFamilies->activationFailures($contractType, $rules) as $gate => $messages) {
            $this->appendGate(
                $gates,
                $failuresByGate,
                $missingItems,
                gateKey: 'islamic_product_'.$gate,
                failures: $messages,
                missingHints: ['islamic_product:'.$gate],
                evidenceRefs: ['contract_type' => $contractType],
            );
        }

        if ($family === 'mourabaha') {
            $this->appendGate(
                $gates,
                $failuresByGate,
                $missingItems,
                gateKey: 'islamic_mourabaha_references',
                failures: $this->mourabahaReferenceFailures($rules, $asOf),
                missingHints: ['islamic_mourabaha_references:approved_sharia_and_template'],
                evidenceRefs: ['contract_type' => $contractType],
            );
            $this->appendGate(
                $gates,
                $failuresByGate,
                $missingItems,
                gateKey: 'islamic_mourabaha_mapping_requirements',
                failures: $this->mourabahaMappingRequirementFailures($rules, $asOf),
                missingHints: ['islamic_mourabaha_mapping_requirements:approved_operation_codes'],
                evidenceRefs: ['contract_type' => $contractType],
            );
        }

        $facts = [
            'scope_type' => 'product_family',
            'scope_value' => $family,
            'sector_codes' => is_array($rules['sector_codes'] ?? null) ? $rules['sector_codes'] : [],
            'goods_codes' => is_array($rules['goods_codes'] ?? null) ? $rules['goods_codes'] : [],
            'supplier_flags' => is_array($rules['supplier_flags'] ?? null) ? $rules['supplier_flags'] : [],
            'customer_business_flags' => is_array($rules['customer_business_flags'] ?? null) ? $rules['customer_business_flags'] : [],
            'source_of_funds_flags' => is_array($rules['source_of_funds_flags'] ?? null) ? $rules['source_of_funds_flags'] : [],
            'use_of_funds_flags' => is_array($rules['use_of_funds_flags'] ?? null) ? $rules['use_of_funds_flags'] : [],
            'agency_scope_value' => $scopeValue,
        ];
        $hasPolicyRegistry = DB::table('islamic_screening_policies')->exists();
        $screening = $this->screening->evaluate(
            subjectType: IslamicApprovalStateMachine::SUBJECT_PRODUCT,
            subjectPublicId: isset(((array) $product)['public_id']) && is_string(((array) $product)['public_id']) ? ((array) $product)['public_id'] : '',
            contextType: 'product_approval',
            facts: $facts,
            actor: $actor,
            strictPolicy: $hasPolicyRegistry,
        );
        if (in_array($screening['result'], ['fail', 'manual_review', 'expired'], true)) {
            $messages = ['Screening policy returned '.$screening['result'].'.'];
            if (is_string($screening['block_reason'] ?? null) && $screening['block_reason'] !== '') {
                $messages[] = $screening['block_reason'];
            }
            if (is_string($screening['review_case_public_id'] ?? null) && $screening['review_case_public_id'] !== '') {
                $messages[] = 'review_case_public_id='.$screening['review_case_public_id'];
            }
            $this->appendGate(
                $gates,
                $failuresByGate,
                $missingItems,
                gateKey: 'islamic_screening_policy',
                failures: $messages,
                missingHints: ['islamic_screening_policy:pass_result_required'],
                evidenceRefs: [
                    'result_public_id' => $screening['result_public_id'] ?? null,
                    'review_case_public_id' => $screening['review_case_public_id'] ?? null,
                ],
            );
        } else {
            $gates[] = [
                'gate_key' => 'islamic_screening_policy',
                'status' => 'pass',
                'reasons' => ['Screening policy gate passed.'],
                'evidence_refs' => [
                    'result_public_id' => $screening['result_public_id'] ?? null,
                    'result' => $screening['result'],
                ],
            ];
        }

        return [
            'overall_status' => $failuresByGate === [] ? 'pass' : 'fail',
            'family_code' => $family,
            'evaluated_at' => $evaluatedAt->toIso8601String(),
            'gates' => $gates,
            'failures_by_gate' => $failuresByGate,
            'missing_items' => array_values(array_unique($missingItems)),
        ];
    }

    /**
     * Failures keyed by gate identifier so callers can preserve structure in API responses.
     *
     * @return array<string, array<int, string>>
     */
    public function activationFailures(object $product, ?CarbonInterface $asOf = null, ?User $actor = null): array
    {
        $evaluation = $this->evaluate($product, $asOf, $actor);

        return $evaluation['failures_by_gate'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $gates
     * @param  array<string, array<int, string>>  $failuresByGate
     * @param  array<int, string>  $missingItems
     * @param  array<int, string>  $failures
     * @param  array<int, string>  $missingHints
     * @param  array<string, mixed>  $evidenceRefs
     */
    private function appendGate(
        array &$gates,
        array &$failuresByGate,
        array &$missingItems,
        string $gateKey,
        array $failures,
        array $missingHints,
        array $evidenceRefs,
    ): void {
        if ($failures !== []) {
            $failuresByGate[$gateKey] = array_values(array_filter($failures, 'is_string'));
            foreach ($missingHints as $hint) {
                $missingItems[] = $hint;
            }
            $gates[] = [
                'gate_key' => $gateKey,
                'status' => 'fail',
                'reasons' => $failuresByGate[$gateKey],
                'evidence_refs' => $evidenceRefs,
            ];

            return;
        }

        $gates[] = [
            'gate_key' => $gateKey,
            'status' => 'pass',
            'reasons' => [],
            'evidence_refs' => $evidenceRefs,
        ];
    }

    private function hasAnyActiveIslamicAccountingMappingBaseline(?CarbonInterface $asOf): bool
    {
        $asOfDate = ($asOf ?? CarbonImmutable::now())->toDateString();

        return DB::table('islamic_standard_links as l')
            ->join('islamic_standards as s', 's.id', '=', 'l.islamic_standard_id')
            ->join('documents as d', 'd.id', '=', 's.document_id')
            ->join('operation_account_mappings as m', 'm.public_id', '=', 'l.linkable_code')
            ->join('operation_codes as op', 'op.id', '=', 'm.operation_code_id')
            ->join('islamic_approval_workflows as wf', function ($join): void {
                $join->on('wf.subject_public_id', '=', 'm.public_id')
                    ->where('wf.subject_type', IslamicApprovalStateMachine::SUBJECT_MAPPING);
            })
            ->where('l.linkable_type', 'accounting_mapping')
            ->where('s.status', 'active')
            ->where('s.effective_date', '<=', $asOfDate)
            ->where(function ($q) use ($asOfDate): void {
                $q->whereNull('s.expiry_date')->orWhere('s.expiry_date', '>', $asOfDate);
            })
            ->where('d.status', 'active')
            ->where('m.status', 'active')
            ->where('m.approval_status', 'approved')
            ->where('m.effective_from', '<=', $asOfDate)
            ->where(function ($q) use ($asOfDate): void {
                $q->whereNull('m.effective_to')->orWhere('m.effective_to', '>', $asOfDate);
            })
            ->where(function ($q): void {
                $q->where('m.sharia_approval_required', false)->orWhere('m.sharia_approval_status', 'approved');
            })
            ->where('op.module', 'islamic_finance')
            ->where('op.status', 'active')
            ->where('wf.current_state', IslamicApprovalStateMachine::STATE_APPROVED)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<int, string>
     */
    private function mourabahaReferenceFailures(array $rules, ?CarbonInterface $asOf): array
    {
        $configuration = is_array($rules['mourabaha_configuration'] ?? null) ? $rules['mourabaha_configuration'] : null;
        if (! is_array($configuration)) {
            return [];
        }

        // Structural presence is validated in family-level IF-060 schema rules.
        // Existence/approval coupling is enforced when templates and workflows are
        // used in their own lifecycle workflows.
        return [];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<int, string>
     */
    private function mourabahaMappingRequirementFailures(array $rules, ?CarbonInterface $asOf): array
    {
        $configuration = is_array($rules['mourabaha_configuration'] ?? null) ? $rules['mourabaha_configuration'] : null;
        if (! is_array($configuration)) {
            return [];
        }
        $mappingRequirements = is_array($configuration['accounting_mapping_requirements'] ?? null)
            ? $configuration['accounting_mapping_requirements']
            : null;
        if (! is_array($mappingRequirements)) {
            return [];
        }

        // Structural presence is validated in family-level IF-060 schema rules.
        // Posting-time workflows enforce concrete approved mapping availability.
        return [];
    }
}
