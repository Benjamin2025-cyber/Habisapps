<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use InvalidArgumentException;

final class IslamicProductFamilyRegistry
{
    /** @var list<string> */
    private const array MOURABAHA_REQUIRED_CONFIGURATION_SECTIONS = [
        'allowed_asset_categories',
        'allowed_costs_policy',
        'margin_rule',
        'repayment_schedule_rules',
        'delivery_requirements',
        'early_settlement_policy',
        'late_payment_policy',
        'cancellation_policy',
        'accounting_mapping_requirements',
        'sharia_approval_reference',
        'contract_template_reference',
    ];

    /** @var list<string> */
    private const array MOURABAHA_REQUIRED_OPERATION_CODES = [
        'murabaha_receivable',
        'murabaha_payable',
        'murabaha_profit',
    ];

    /** @var list<string> */
    private const array FORBIDDEN_MOURABAHA_SEMANTIC_TOKENS = [
        'interest',
        'apr',
        'libor',
        'ibor',
        'time_value',
    ];

    /** @var list<string> */
    private const array SALAM_ALLOWED_RULE_KEYS = [
        'document_requirements',
        'authorization_rules',
        'operational_procedure',
        'reporting_category',
        'sector_codes',
        'goods_codes',
        'supplier_flags',
        'customer_business_flags',
        'source_of_funds_flags',
        'use_of_funds_flags',
        'allowed_goods_policy',
        'specification_requirements',
        'payment_timing_policy',
        'delivery_rules',
        'inspection_rules',
        'substitution_policy',
        'non_delivery_policy',
        'parallel_salam_policy',
        'upfront_payment_mapping',
        'accounting_mapping_profile',
        'contract_template_reference',
        'screening_policy_binding',
        'cash_only',
    ];

    /** @var list<string> */
    private const array ISTISNAA_ALLOWED_RULE_KEYS = [
        'document_requirements',
        'authorization_rules',
        'operational_procedure',
        'reporting_category',
        'sector_codes',
        'goods_codes',
        'supplier_flags',
        'customer_business_flags',
        'source_of_funds_flags',
        'use_of_funds_flags',
        'project_categories_policy',
        'milestone_rules',
        'inspection_rules',
        'payment_rules',
        'variation_rules',
        'delivery_acceptance_rules',
        'defect_rules',
        'parallel_istisnaa_policy',
        'project_accounting_mapping_profile',
        'contract_template_reference',
        'screening_policy_binding',
    ];

    /** @var list<string> */
    private const array MOUDARABA_ALLOWED_RULE_KEYS = [
        'document_requirements',
        'authorization_rules',
        'operational_procedure',
        'reporting_category',
        'sector_codes',
        'goods_codes',
        'supplier_flags',
        'customer_business_flags',
        'source_of_funds_flags',
        'use_of_funds_flags',
        'eligible_business_activities_policy',
        'capital_rules',
        'profit_sharing_ratio_rules',
        'reporting_cadence_policy',
        'evidence_requirements_policy',
        'loss_rules',
        'misconduct_negligence_breach_rules',
        'liquidation_rules',
        'accounting_mapping_profile',
        'contract_template_reference',
        'screening_policy_binding',
        'guaranteed_return',
        'guaranteed_minimum_return',
        'fixed_institution_return',
        'fixed_profit_amount',
    ];

    /** @var array<string, string> */
    private const array CONTRACT_TYPE_TO_FAMILY = [
        'murabaha' => 'mourabaha',
        'mourabaha' => 'mourabaha',
        'ijara' => 'ijara',
        'ijara_wa_iqtina' => 'ijara_wa_iqtina',
        'salam' => 'salam',
        'istisnaa' => 'istisnaa',
        'moudaraba' => 'moudaraba',
        'moucharaka' => 'moucharaka',
        'islamic_current_account' => 'islamic_current_account',
        'islamic_savings_account' => 'islamic_savings_account',
        'islamic_investment_account' => 'islamic_investment_account',
    ];

    /** @var list<string> */
    private const array WRITABLE_CONTRACT_TYPES = [
        'murabaha',
        'ijara',
        'ijara_wa_iqtina',
        'salam',
        'istisnaa',
        'moudaraba',
        'moucharaka',
        'islamic_current_account',
        'islamic_savings_account',
        'islamic_investment_account',
    ];

    /** @var array<string, array<string, mixed>> */
    private const array FAMILY_METADATA = [
        'mourabaha' => [
            'family_kind' => 'financing',
            'display_name' => 'Mourabaha',
            'required_fields' => ['purchase_cost_minor', 'markup_minor', 'asset_evidence'],
            'workflow_states' => ['draft', 'approved', 'active', 'settled', 'cancelled'],
            'evidence_rules' => ['asset_purchase', 'supplier_invoice', 'customer_acceptance'],
            'accounting_events' => ['sale_receivable', 'cost_payable', 'deferred_profit', 'collection'],
            'screening_rules' => ['product_approval', 'contract_approval', 'asset_acceptance'],
            'reporting_category' => 'mourabaha_receivables',
            'readiness_checklist' => ['standards', 'regulatory_signoff', 'screening_policy', 'mapping_profile'],
        ],
        'ijara' => [
            'family_kind' => 'financing',
            'display_name' => 'Ijara',
            'required_fields' => ['leased_asset_categories', 'rental_rules', 'maintenance_policy'],
            'workflow_states' => ['draft', 'active', 'suspended', 'terminated', 'closed'],
            'evidence_rules' => ['asset_control', 'condition_report', 'lease_acceptance'],
            'accounting_events' => ['rental_receivable', 'rental_income', 'termination_adjustment'],
            'screening_rules' => ['product_approval', 'contract_approval', 'asset_acceptance'],
            'reporting_category' => 'ijara_rentals',
            'readiness_checklist' => ['maintenance_policy', 'rental_rules', 'takaful_policy', 'mapping_profile'],
        ],
        'ijara_wa_iqtina' => [
            'family_kind' => 'financing',
            'display_name' => 'Ijara wa Iqtina',
            'required_fields' => ['leased_asset_categories', 'rental_rules', 'maintenance_policy', 'residual_value_policy'],
            'workflow_states' => ['draft', 'active', 'transfer_pending', 'transferred', 'closed'],
            'evidence_rules' => ['asset_control', 'condition_report', 'transfer_document', 'customer_acceptance'],
            'accounting_events' => ['rental_receivable', 'rental_income', 'residual_transfer'],
            'screening_rules' => ['product_approval', 'contract_approval', 'asset_acceptance'],
            'reporting_category' => 'ijara_transfers',
            'readiness_checklist' => ['maintenance_policy', 'residual_value_policy', 'transfer_terms', 'mapping_profile'],
        ],
        'salam' => [
            'family_kind' => 'financing',
            'display_name' => 'Salam',
            'required_fields' => ['allowed_goods_policy', 'delivery_rules', 'upfront_payment_mapping'],
            'workflow_states' => ['draft', 'approved', 'paid', 'partially_delivered', 'settled'],
            'evidence_rules' => ['goods_specification', 'delivery_terms', 'inspection_result'],
            'accounting_events' => ['upfront_payment', 'inventory_recognition', 'settlement_adjustment'],
            'screening_rules' => ['product_approval', 'contract_approval', 'goods_acceptance'],
            'reporting_category' => 'salam_goods_commitments',
            'readiness_checklist' => ['allowed_goods_policy', 'inspection_rules', 'non_delivery_policy', 'mapping_profile'],
        ],
        'istisnaa' => [
            'family_kind' => 'financing',
            'display_name' => "Istisna'a",
            'required_fields' => ['project_categories_policy', 'milestone_rules', 'variation_rules', 'project_accounting_mapping_profile'],
            'workflow_states' => ['draft', 'in_execution', 'milestone_approved', 'delivered', 'closed'],
            'evidence_rules' => ['project_specs', 'milestone_inspection', 'delivery_acceptance'],
            'accounting_events' => ['milestone_payment', 'variation_adjustment', 'final_settlement'],
            'screening_rules' => ['product_approval', 'contract_approval', 'asset_acceptance'],
            'reporting_category' => 'istisnaa_projects',
            'readiness_checklist' => ['milestone_rules', 'variation_rules', 'inspection_rules', 'mapping_profile'],
        ],
        'moudaraba' => [
            'family_kind' => 'financing',
            'display_name' => 'Moudaraba',
            'required_fields' => ['capital_rules', 'profit_sharing_ratio_rules', 'reporting_cadence_policy', 'loss_rules'],
            'workflow_states' => ['draft', 'active', 'report_pending', 'distribution_ready', 'liquidated'],
            'evidence_rules' => ['business_plan', 'screening_result', 'periodic_report', 'profit_declaration'],
            'accounting_events' => ['capital_disbursement', 'profit_distribution', 'loss_recognition', 'liquidation'],
            'screening_rules' => ['product_approval', 'contract_approval'],
            'reporting_category' => 'moudaraba_investments',
            'readiness_checklist' => ['reporting_cadence_policy', 'loss_rules', 'liquidation_rules', 'mapping_profile'],
        ],
        'moucharaka' => [
            'family_kind' => 'financing',
            'display_name' => 'Moucharaka',
            'required_fields' => ['contribution_rules', 'contribution_evidence_policy', 'profit_ratio_rules', 'loss_ratio_rules'],
            'workflow_states' => ['draft', 'active', 'distribution_ready', 'buyout_pending', 'closed'],
            'evidence_rules' => ['partner_contribution', 'approved_report', 'valuation_approval'],
            'accounting_events' => ['contribution', 'profit_distribution', 'loss_allocation', 'buyout', 'exit'],
            'screening_rules' => ['product_approval', 'contract_approval'],
            'reporting_category' => 'moucharaka_partnerships',
            'readiness_checklist' => ['contribution_evidence_policy', 'loss_ratio_rules', 'valuation_policy', 'mapping_profile'],
        ],
        'islamic_current_account' => [
            'family_kind' => 'account',
            'display_name' => 'Islamic Current Account',
            'required_fields' => ['legal_sharia_basis', 'fees', 'statement_labels', 'account_mappings'],
            'workflow_states' => ['draft', 'active', 'restricted', 'closed'],
            'evidence_rules' => ['account_agreement', 'approved_statement_labels'],
            'accounting_events' => ['deposit', 'withdrawal', 'fee', 'closure'],
            'screening_rules' => ['product_approval'],
            'reporting_category' => 'islamic_current_accounts',
            'readiness_checklist' => ['interest_disabled', 'statement_labels', 'mapping_profile'],
        ],
        'islamic_savings_account' => [
            'family_kind' => 'account',
            'display_name' => 'Islamic Savings Account',
            'required_fields' => ['profit_pool', 'distribution_ratio', 'withdrawal_rules', 'loss_policy'],
            'workflow_states' => ['draft', 'active', 'distribution_pending', 'closed'],
            'evidence_rules' => ['account_agreement', 'approved_pool_result'],
            'accounting_events' => ['deposit', 'withdrawal', 'profit_distribution', 'loss_allocation'],
            'screening_rules' => ['product_approval'],
            'reporting_category' => 'islamic_savings_accounts',
            'readiness_checklist' => ['profit_pool', 'reserve_policy', 'loss_policy', 'mapping_profile'],
        ],
        'islamic_investment_account' => [
            'family_kind' => 'account',
            'display_name' => 'Islamic Investment Account',
            'required_fields' => ['pool', 'tenor', 'risk_disclosure', 'profit_ratio', 'loss_treatment'],
            'workflow_states' => ['draft', 'active', 'matured', 'closed'],
            'evidence_rules' => ['account_agreement', 'risk_disclosure', 'approved_pool_performance'],
            'accounting_events' => ['investment_deposit', 'profit_distribution', 'loss_allocation', 'withdrawal'],
            'screening_rules' => ['product_approval'],
            'reporting_category' => 'islamic_investment_accounts',
            'readiness_checklist' => ['risk_disclosure', 'pool_policy', 'loss_treatment', 'mapping_profile'],
        ],
    ];

    /** @return list<string> */
    public static function supportedContractTypes(): array
    {
        return self::WRITABLE_CONTRACT_TYPES;
    }

    /** @return list<array<string, mixed>> */
    public function allMetadata(): array
    {
        $families = [];
        foreach (self::FAMILY_METADATA as $code => $metadata) {
            $families[] = $this->metadataPayload($code, $metadata);
        }

        usort($families, static fn (array $a, array $b): int => [$a['family_kind'], $a['code']] <=> [$b['family_kind'], $b['code']]);

        return $families;
    }

    /** @return array<string, mixed>|null */
    public function metadataFor(string $familyCode): ?array
    {
        $canonical = self::familyForContractType($familyCode) ?? $familyCode;
        $metadata = self::FAMILY_METADATA[$canonical] ?? null;
        if ($metadata === null) {
            return null;
        }

        return $this->metadataPayload($canonical, $metadata);
    }

    public static function familyForContractType(string $contractType): ?string
    {
        return self::CONTRACT_TYPE_TO_FAMILY[$contractType] ?? null;
    }

    public function familyKindFor(string $familyCode): ?string
    {
        $canonical = self::familyForContractType($familyCode) ?? $familyCode;
        $metadata = self::FAMILY_METADATA[$canonical] ?? null;
        if (! is_array($metadata)) {
            return null;
        }

        return $metadata['family_kind'];
    }

    /**
     * Reject explicit contradictions at draft creation time while allowing incomplete drafts
     * to move through the normal readiness-review flow.
     *
     * @param  array<string, mixed>  $rules
     */
    public function assertDraftRulesAllowed(string $contractType, array $rules): void
    {
        $rules = $this->stringKeyedArrayOrEmpty($rules);
        $family = self::familyForContractType($contractType);
        if ($family === null) {
            throw new InvalidArgumentException('Unsupported Islamic product family.');
        }

        if ($family === 'mourabaha') {
            $configuration = $rules['mourabaha_configuration'] ?? null;
            if (! is_array($configuration)) {
                throw new InvalidArgumentException('Mourabaha products must include rules.mourabaha_configuration.');
            }

            $normalizedConfiguration = $this->stringKeyedArrayOrEmpty($configuration);
            $failures = $this->mourabahaConfigurationFailures($normalizedConfiguration);
            if ($failures !== []) {
                $messages = [];
                foreach ($failures as $gate => $gateMessages) {
                    foreach ($gateMessages as $message) {
                        $messages[] = $gate.': '.$message;
                    }
                }

                throw new InvalidArgumentException(__('islamic_governance.invalid_mourabaha_configuration', ['reasons' => implode(' ', $messages)]));
            }
        }

        if ($family === 'ijara' || $family === 'ijara_wa_iqtina') {
            $transferOption = $rules['transfer_option'] ?? null;
            if ($family === 'ijara' && $transferOption === true) {
                throw new InvalidArgumentException('Ordinary Ijara cannot enable transfer_option. Use Ijara wa Iqtina for ownership transfer.');
            }
            if ($family === 'ijara_wa_iqtina' && $transferOption !== true) {
                throw new InvalidArgumentException('Ijara wa Iqtina must explicitly enable transfer_option.');
            }
        }

        if ($family === 'salam') {
            $shapeFailures = $this->salamShapeFailures($rules);
            if ($shapeFailures !== []) {
                $messages = [];
                foreach ($shapeFailures as $gate => $gateMessages) {
                    foreach ($gateMessages as $message) {
                        $messages[] = $gate.': '.$message;
                    }
                }

                throw new InvalidArgumentException(__('islamic_governance.invalid_salam_rules_configuration', ['reasons' => implode(' ', $messages)]));
            }
        }

        if ($family === 'istisnaa') {
            $shapeFailures = $this->istisnaaShapeFailures($rules);
            if ($shapeFailures !== []) {
                $messages = [];
                foreach ($shapeFailures as $gate => $gateMessages) {
                    foreach ($gateMessages as $message) {
                        $messages[] = $gate.': '.$message;
                    }
                }

                throw new InvalidArgumentException(__('islamic_governance.invalid_istisnaa_rules_configuration', ['reasons' => implode(' ', $messages)]));
            }
        }

        if ($family === 'moudaraba' && $this->hasTruthyAny($rules, [
            'guaranteed_return',
            'guaranteed_minimum_return',
            'fixed_institution_return',
            'fixed_profit_amount',
        ])) {
            throw new InvalidArgumentException('Moudaraba cannot configure guaranteed return or fixed institution profit entitlement.');
        }
        if ($family === 'moudaraba') {
            $shapeFailures = $this->moudarabaShapeFailures($rules);
            if ($shapeFailures !== []) {
                $messages = [];
                foreach ($shapeFailures as $gate => $gateMessages) {
                    foreach ($gateMessages as $message) {
                        $messages[] = $gate.': '.$message;
                    }
                }

                throw new InvalidArgumentException(__('islamic_governance.invalid_moudaraba_rules_configuration', ['reasons' => implode(' ', $messages)]));
            }
        }

        $lossRatioRules = is_array($rules['loss_ratio_rules'] ?? null) ? $rules['loss_ratio_rules'] : null;
        if ($family === 'moucharaka'
            && $this->stringValue($lossRatioRules['allocation_basis'] ?? null) === 'profit_ratio'
            && ! $this->hasApprovedException($lossRatioRules)
        ) {
            throw new InvalidArgumentException('Moucharaka loss allocation cannot follow profit ratio without an approved exception.');
        }
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, array<int, string>>
     */
    public function activationFailures(string $contractType, array $rules): array
    {
        $rules = $this->stringKeyedArrayOrEmpty($rules);
        $family = self::familyForContractType($contractType);
        if ($family === null) {
            return ['islamic_product_family' => ['Unsupported Islamic product family.']];
        }

        return match ($family) {
            'mourabaha' => $this->mourabahaFailures($rules),
            'ijara' => $this->ijaraFailures($rules, transferVariant: false),
            'ijara_wa_iqtina' => $this->ijaraFailures($rules, transferVariant: true),
            'salam' => $this->salamFailures($rules),
            'istisnaa' => $this->istisnaaFailures($rules),
            'moudaraba' => $this->moudarabaFailures($rules),
            'moucharaka' => $this->moucharakaFailures($rules),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, array<int, string>>
     */
    private function mourabahaFailures(array $rules): array
    {
        $configuration = $rules['mourabaha_configuration'] ?? null;
        if (! is_array($configuration)) {
            return ['mourabaha_configuration' => ['Mourabaha configuration is required for activation.']];
        }

        $normalized = $this->stringKeyedArrayOrEmpty($configuration);

        return $this->mourabahaConfigurationFailures($normalized);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, array<int, string>>
     */
    private function mourabahaConfigurationFailures(array $configuration): array
    {
        $failures = [];

        foreach (self::MOURABAHA_REQUIRED_CONFIGURATION_SECTIONS as $section) {
            if (! $this->hasRule($configuration, $section)) {
                $failures['mourabaha_configuration.'.$section][] = 'Mourabaha configuration section is required: '.$section.'.';
            }
        }

        if (is_array($configuration['allowed_asset_categories'] ?? null)
            && $this->listOfStrings($configuration['allowed_asset_categories']) === []
        ) {
            $failures['mourabaha_configuration.allowed_asset_categories'][] = 'Allowed asset categories must contain at least one value.';
        }

        if (is_array($configuration['delivery_requirements'] ?? null)
            && $this->listOfStrings($configuration['delivery_requirements']) === []
        ) {
            $failures['mourabaha_configuration.delivery_requirements'][] = 'Delivery requirements must contain at least one value.';
        }

        if (is_array($configuration['margin_rule'] ?? null)) {
            $marginRule = $configuration['margin_rule'];
            if (($marginRule['compounding'] ?? false) === true) {
                $failures['mourabaha_configuration.margin_rule'][] = 'Compounding is forbidden for Mourabaha margin rule.';
            }
            if (is_string($marginRule['calculus_class'] ?? null) && $this->tokenForbidden($marginRule['calculus_class'])) {
                $failures['mourabaha_configuration.margin_rule'][] = 'Margin rule cannot use interest-like calculus classes.';
            }
        }

        if (is_array($configuration['repayment_schedule_rules'] ?? null)) {
            $scheduleRules = $configuration['repayment_schedule_rules'];
            if (is_string($scheduleRules['calculation_mode'] ?? null) && $this->tokenForbidden($scheduleRules['calculation_mode'])) {
                $failures['mourabaha_configuration.repayment_schedule_rules'][] = 'Repayment schedule calculation mode cannot use interest semantics.';
            }
        }

        if (is_array($configuration['late_payment_policy'] ?? null)) {
            $latePolicy = $configuration['late_payment_policy'];
            if (($latePolicy['compounding'] ?? false) === true) {
                $failures['mourabaha_configuration.late_payment_policy'][] = 'Late-payment policy cannot compound penalties.';
            }
            if (is_string($latePolicy['mode'] ?? null) && $this->tokenForbidden($latePolicy['mode'])) {
                $failures['mourabaha_configuration.late_payment_policy'][] = 'Late-payment policy cannot use interest-like mode.';
            }
        }

        if (is_array($configuration['accounting_mapping_requirements'] ?? null)) {
            $mapping = $configuration['accounting_mapping_requirements'];
            $operationCodes = is_array($mapping['operation_codes'] ?? null)
                ? $this->listOfStrings($mapping['operation_codes'])
                : [];
            if ($operationCodes === []) {
                $failures['mourabaha_configuration.accounting_mapping_requirements'][] = 'Accounting mapping requirements must include operation_codes.';
            } else {
                $missing = array_values(array_diff(self::MOURABAHA_REQUIRED_OPERATION_CODES, $operationCodes));
                if ($missing !== []) {
                    $failures['mourabaha_configuration.accounting_mapping_requirements'][] = 'Missing required operation_codes: '.implode(', ', $missing).'.';
                }
                foreach ($operationCodes as $code) {
                    if ($this->tokenForbidden($code)) {
                        $failures['mourabaha_configuration.accounting_mapping_requirements'][] = 'Conventional interest mapping is forbidden in operation_codes: '.$code.'.';
                    }
                }
            }
        }

        if (is_array($configuration['sharia_approval_reference'] ?? null)) {
            $reference = $configuration['sharia_approval_reference'];
            if (! is_string($reference['workflow_public_id'] ?? null) || trim($reference['workflow_public_id']) === '') {
                $failures['mourabaha_configuration.sharia_approval_reference'][] = 'Sharia approval reference must include workflow_public_id.';
            }
            if (! is_string($reference['decision_reference'] ?? null) || trim($reference['decision_reference']) === '') {
                $failures['mourabaha_configuration.sharia_approval_reference'][] = 'Sharia approval reference must include decision_reference.';
            }
        }

        if (is_array($configuration['contract_template_reference'] ?? null)) {
            $reference = $configuration['contract_template_reference'];
            if (! is_string($reference['template_public_id'] ?? null) || trim($reference['template_public_id']) === '') {
                $failures['mourabaha_configuration.contract_template_reference'][] = 'Contract template reference must include template_public_id.';
            }
            if (! is_numeric($reference['version'] ?? null) || (int) $reference['version'] < 1) {
                $failures['mourabaha_configuration.contract_template_reference'][] = 'Contract template reference must include a positive version.';
            }
        }

        if ($this->containsForbiddenMourabahaSemantics($configuration)) {
            $failures['mourabaha_configuration_interest_formula'][] = 'Mourabaha configuration cannot include interest-formula semantics.';
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, array<int, string>>
     */
    private function ijaraFailures(array $rules, bool $transferVariant): array
    {
        $failures = [];
        $this->requireRule($failures, $rules, 'leased_asset_categories', 'Leased asset categories are required for Ijara activation.');
        if (is_array($rules['leased_asset_categories'] ?? null) && $this->listOfStrings($rules['leased_asset_categories']) === []) {
            $failures['leased_asset_categories'][] = 'Leased asset categories must contain at least one category.';
        }
        $this->requireRule($failures, $rules, 'rental_rules', 'Rental rules are required for Ijara activation.');
        $this->requireRule($failures, $rules, 'maintenance_policy', 'Maintenance policy is required for Ijara activation.');
        $this->requireRule($failures, $rules, 'takaful_policy', 'Takaful policy is required for Ijara activation.');
        $this->requireRule($failures, $rules, 'damage_loss_rules', 'Damage/loss rules are required for Ijara activation.');
        $this->requireRule($failures, $rules, 'termination_rules', 'Termination rules are required for Ijara activation.');
        $this->requireRule($failures, $rules, 'accounting_mapping_profile', 'Accounting mapping profile is required for Ijara activation.');
        $this->requireRule($failures, $rules, 'contract_template_reference', 'Contract template reference is required for Ijara activation.');

        if ($transferVariant) {
            if (($rules['transfer_option'] ?? null) !== true) {
                $failures['transfer_option'][] = 'Ijara wa Iqtina requires transfer_option=true.';
            }
            $this->requireRule($failures, $rules, 'transfer_terms', 'Transfer terms are required for Ijara wa Iqtina activation.');
        } elseif (($rules['transfer_option'] ?? null) === true) {
            $failures['transfer_option'][] = 'Ordinary Ijara cannot activate with transfer_option=true.';
        }

        if (is_array($rules['contract_template_reference'] ?? null)) {
            $templateRef = $rules['contract_template_reference'];
            $requiredTemplateCode = $transferVariant ? 'ijara_wa_iqtina_contract_template' : 'ijara_contract_template';
            $templateCode = is_string($templateRef['template_code'] ?? null) ? trim($templateRef['template_code']) : '';
            if ($templateCode !== '' && $templateCode !== $requiredTemplateCode) {
                $failures['contract_template_reference'][] = sprintf(
                    'Template code must match variant template (%s).',
                    $requiredTemplateCode,
                );
            }
        }

        if ($transferVariant) {
            $this->requireRule($failures, $rules, 'residual_value_policy', 'Residual policy is required for Ijara wa Iqtina activation.');
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, array<int, string>>
     */
    private function salamFailures(array $rules): array
    {
        $failures = [];
        foreach ($this->salamShapeFailures($rules) as $key => $messages) {
            $failures[$key] = array_merge($failures[$key] ?? [], $messages);
        }
        $this->requireRule($failures, $rules, 'allowed_goods_policy', 'Allowed goods policy is required for Salam activation.');
        $this->requireRule($failures, $rules, 'specification_requirements', 'Specification requirements are required for Salam activation.');
        $this->requireRule($failures, $rules, 'payment_timing_policy', 'Payment timing policy is required for Salam activation.');
        $this->requireRule($failures, $rules, 'delivery_rules', 'Delivery rules are required for Salam activation.');
        $this->requireRule($failures, $rules, 'inspection_rules', 'Inspection rules are required for Salam activation.');
        $this->requireRule($failures, $rules, 'substitution_policy', 'Substitution policy is required for Salam activation.');
        $this->requireRule($failures, $rules, 'non_delivery_policy', 'Non-delivery policy is required for Salam activation.');
        $this->requireRule($failures, $rules, 'parallel_salam_policy', 'Parallel Salam policy is required for Salam activation.');
        $this->requireRule($failures, $rules, 'upfront_payment_mapping', 'Upfront payment mapping is required for Salam activation.');
        $this->requireRule($failures, $rules, 'accounting_mapping_profile', 'Accounting mapping profile is required for Salam activation.');
        if (($rules['cash_only'] ?? false) === true) {
            $failures['cash_only'][] = 'Salam cannot be configured as unrestricted cash financing.';
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, array<int, string>>
     */
    private function salamShapeFailures(array $rules): array
    {
        $failures = [];

        foreach (array_keys($rules) as $key) {
            if (! in_array($key, self::SALAM_ALLOWED_RULE_KEYS, true)) {
                $failures['rules_schema'][] = sprintf('Unknown Salam rule key "%s" is not allowed.', $key);
            }
        }

        $allowedGoods = $rules['allowed_goods_policy'] ?? null;
        if ($allowedGoods !== null) {
            if (! is_array($allowedGoods)) {
                $failures['allowed_goods_policy'][] = 'Allowed goods policy must be an object/array.';
            } else {
                $categories = is_array($allowedGoods['categories'] ?? null) ? $this->listOfStrings($allowedGoods['categories']) : [];
                $codes = is_array($allowedGoods['codes'] ?? null) ? $this->listOfStrings($allowedGoods['codes']) : [];
                if ($categories === [] && $codes === []) {
                    $failures['allowed_goods_policy'][] = 'Allowed goods policy must include at least one category or goods code.';
                }
                foreach (array_merge($categories, $codes) as $value) {
                    if ($value === '*' || strtolower($value) === 'all' || strtolower($value) === 'any') {
                        $failures['allowed_goods_policy'][] = 'Wildcard allowed goods values are forbidden for Salam.';
                        break;
                    }
                }
            }
        }

        $paymentTimingPolicy = $rules['payment_timing_policy'] ?? null;
        if ($paymentTimingPolicy !== null) {
            if (! is_array($paymentTimingPolicy)) {
                $failures['payment_timing_policy'][] = 'Payment timing policy must be an object/array.';
            } else {
                $mode = $this->stringValue($paymentTimingPolicy['mode'] ?? null);
                $upfrontRequired = ($paymentTimingPolicy['upfront_required'] ?? false) === true;
                if (! ($mode === 'upfront' || $upfrontRequired)) {
                    $failures['payment_timing_policy'][] = 'Salam payment timing must enforce upfront payment.';
                }
            }
        }

        $parallelSalamPolicy = $rules['parallel_salam_policy'] ?? null;
        if (is_array($parallelSalamPolicy) && (($parallelSalamPolicy['enabled'] ?? false) === true)) {
            if (($parallelSalamPolicy['counterparty_separation_required'] ?? false) !== true) {
                $failures['parallel_salam_policy'][] = 'Parallel Salam requires counterparty separation controls.';
            }
            $riskControls = is_array($parallelSalamPolicy['risk_controls'] ?? null)
                ? $this->listOfStrings($parallelSalamPolicy['risk_controls'])
                : [];
            if ($riskControls === []) {
                $failures['parallel_salam_policy'][] = 'Parallel Salam requires explicit risk controls.';
            }
        }

        $upfrontMapping = $rules['upfront_payment_mapping'] ?? null;
        if ($upfrontMapping !== null) {
            if (! is_array($upfrontMapping)) {
                $failures['upfront_payment_mapping'][] = 'Upfront payment mapping must be an object/array.';
            } else {
                $operationCode = $this->stringValue($upfrontMapping['operation_code'] ?? null);
                if ($operationCode !== '' && $this->tokenForbidden($operationCode)) {
                    $failures['upfront_payment_mapping'][] = 'Upfront payment operation_code cannot use interest-like semantics.';
                }
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, array<int, string>>
     */
    private function istisnaaFailures(array $rules): array
    {
        $failures = [];
        foreach ($this->istisnaaShapeFailures($rules) as $key => $messages) {
            $failures[$key] = array_merge($failures[$key] ?? [], $messages);
        }
        $this->requireRule($failures, $rules, 'project_categories_policy', "Project categories policy is required for Istisna'a activation.");
        $this->requireRule($failures, $rules, 'milestone_rules', "Milestone policy is required for Istisna'a activation.");
        $this->requireRule($failures, $rules, 'inspection_rules', "Inspection policy is required for Istisna'a activation.");
        $this->requireRule($failures, $rules, 'payment_rules', "Payment policy is required for Istisna'a activation.");
        $this->requireRule($failures, $rules, 'variation_rules', "Variation policy is required for Istisna'a activation.");
        $this->requireRule($failures, $rules, 'delivery_acceptance_rules', "Delivery/acceptance policy is required for Istisna'a activation.");
        $this->requireRule($failures, $rules, 'defect_rules', "Defect policy is required for Istisna'a activation.");
        $this->requireRule($failures, $rules, 'parallel_istisnaa_policy', "Parallel Istisna'a policy is required for activation.");
        $this->requireRule($failures, $rules, 'project_accounting_mapping_profile', "Project mapping is required for Istisna'a activation.");

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, array<int, string>>
     */
    private function istisnaaShapeFailures(array $rules): array
    {
        $failures = [];

        foreach (array_keys($rules) as $key) {
            if (! in_array($key, self::ISTISNAA_ALLOWED_RULE_KEYS, true)) {
                $failures['rules_schema'][] = sprintf("Unknown Istisna'a rule key \"%s\" is not allowed.", $key);
            }
        }

        $paymentRules = $rules['payment_rules'] ?? null;
        if (is_array($paymentRules)) {
            $mode = strtolower($this->stringValue($paymentRules['mode'] ?? ''));
            if (in_array($mode, ['staged', 'progressive'], true)) {
                $milestoneRules = $rules['milestone_rules'] ?? null;
                $milestones = is_array($milestoneRules) && is_array($milestoneRules['milestones'] ?? null)
                    ? $this->listOfStrings($milestoneRules['milestones'])
                    : [];
                if ($milestones === []) {
                    $failures['milestone_rules'][] = "Staged/progressive payment mode requires explicit milestone definitions for Istisna'a.";
                }
            }
        }

        $parallelIstisnaa = $rules['parallel_istisnaa_policy'] ?? null;
        if (is_array($parallelIstisnaa) && (($parallelIstisnaa['enabled'] ?? false) === true)) {
            if (($parallelIstisnaa['counterparty_separation_required'] ?? false) !== true) {
                $failures['parallel_istisnaa_policy'][] = "Parallel Istisna'a requires counterparty separation controls.";
            }
            $riskControls = is_array($parallelIstisnaa['risk_controls'] ?? null)
                ? $this->listOfStrings($parallelIstisnaa['risk_controls'])
                : [];
            if ($riskControls === []) {
                $failures['parallel_istisnaa_policy'][] = "Parallel Istisna'a requires explicit risk controls.";
            }
        }

        $mappingProfile = $rules['project_accounting_mapping_profile'] ?? null;
        if ($mappingProfile !== null && ! is_array($mappingProfile)) {
            $failures['project_accounting_mapping_profile'][] = 'Project accounting mapping profile must be an object/array.';
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, array<int, string>>
     */
    private function moudarabaFailures(array $rules): array
    {
        $failures = [];
        foreach ($this->moudarabaShapeFailures($rules) as $key => $messages) {
            $failures[$key] = array_merge($failures[$key] ?? [], $messages);
        }
        if ($this->hasTruthyAny($rules, ['guaranteed_return', 'guaranteed_minimum_return', 'fixed_institution_return', 'fixed_profit_amount'])) {
            $failures['guaranteed_return'][] = 'Moudaraba cannot configure guaranteed return or fixed institution profit entitlement.';
        }
        $this->requireRule($failures, $rules, 'eligible_business_activities_policy', 'Eligible business activities policy is required for Moudaraba activation.');
        $this->requireRule($failures, $rules, 'capital_rules', 'Capital rules are required for Moudaraba activation.');
        $this->requireRule($failures, $rules, 'profit_sharing_ratio_rules', 'Profit-sharing ratio rules are required for Moudaraba activation.');
        $this->requireRule($failures, $rules, 'reporting_cadence_policy', 'Reporting cadence is required for Moudaraba activation.');
        $this->requireRule($failures, $rules, 'evidence_requirements_policy', 'Evidence requirements policy is required for Moudaraba activation.');
        $this->requireRule($failures, $rules, 'loss_rules', 'Loss-rule policy is required for Moudaraba activation.');
        $this->requireRule($failures, $rules, 'misconduct_negligence_breach_rules', 'Misconduct/negligence/breach policy is required for Moudaraba activation.');
        $this->requireRule($failures, $rules, 'liquidation_rules', 'Liquidation rules are required for Moudaraba activation.');
        $this->requireRule($failures, $rules, 'accounting_mapping_profile', 'Accounting mapping profile is required for Moudaraba activation.');

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, array<int, string>>
     */
    private function moudarabaShapeFailures(array $rules): array
    {
        $failures = [];

        foreach (array_keys($rules) as $key) {
            if (! in_array($key, self::MOUDARABA_ALLOWED_RULE_KEYS, true)) {
                $failures['rules_schema'][] = sprintf('Unknown Moudaraba rule key "%s" is not allowed.', $key);
            }
        }

        $ratioRules = $rules['profit_sharing_ratio_rules'] ?? null;
        if (is_array($ratioRules)) {
            $institutionRatio = is_numeric($ratioRules['institution_ratio'] ?? null) ? (float) $ratioRules['institution_ratio'] : null;
            $entrepreneurRatio = is_numeric($ratioRules['entrepreneur_ratio'] ?? null) ? (float) $ratioRules['entrepreneur_ratio'] : null;
            if ($institutionRatio !== null && $entrepreneurRatio !== null) {
                if (abs(($institutionRatio + $entrepreneurRatio) - 1.0) > 0.0001) {
                    $failures['profit_sharing_ratio_rules'][] = 'Institution and entrepreneur ratios must sum to 1.0.';
                }
            }
            if (is_numeric($ratioRules['fixed_institution_profit_amount_minor'] ?? null) && (int) $ratioRules['fixed_institution_profit_amount_minor'] > 0) {
                $failures['profit_sharing_ratio_rules'][] = 'Fixed institution profit entitlement is forbidden in Moudaraba.';
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, array<int, string>>
     */
    private function moucharakaFailures(array $rules): array
    {
        $failures = [];
        $this->requireRule($failures, $rules, 'contribution_evidence_policy', 'Contribution evidence policy is required for Moucharaka activation.');

        $buyoutPolicy = is_array($rules['buyout_policy'] ?? null) ? $rules['buyout_policy'] : null;
        if (($buyoutPolicy['enabled'] ?? false) === true && ! $this->hasRule($rules, 'valuation_policy')) {
            $failures['valuation_policy'][] = 'Valuation policy is required when Moucharaka buyout is enabled.';
        }

        $lossRatioRules = is_array($rules['loss_ratio_rules'] ?? null) ? $rules['loss_ratio_rules'] : null;
        if ($this->stringValue($lossRatioRules['allocation_basis'] ?? null) === 'profit_ratio'
            && ! $this->hasApprovedException($lossRatioRules)
        ) {
            $failures['loss_ratio_rules'][] = 'Loss by profit ratio requires an approved exception.';
        }

        if (($rules['diminishing_partnership'] ?? false) === true) {
            $this->requireRule($failures, $rules, 'diminishing_transfer_rules', 'Diminishing partnership requires transfer rules.');
            $this->requireRule($failures, $rules, 'diminishing_valuation_rules', 'Diminishing partnership requires valuation rules.');
        }

        return $failures;
    }

    /**
     * @param  array<string, array<int, string>>  $failures
     * @param  array<string, mixed>  $rules
     */
    private function requireRule(array &$failures, array $rules, string $key, string $message): void
    {
        if (! $this->hasRule($rules, $key)) {
            $failures[$key][] = $message;
        }
    }

    /** @param array<string, mixed> $rules */
    private function hasRule(array $rules, string $key): bool
    {
        $value = $rules[$key] ?? null;
        if ($value === null || $value === '') {
            return false;
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $keys
     */
    private function hasTruthyAny(array $rules, array $keys): bool
    {
        foreach ($keys as $key) {
            if (($rules[$key] ?? false) === true || (is_numeric($rules[$key] ?? null) && (float) $rules[$key] > 0)) {
                return true;
            }
        }

        return false;
    }

    private function hasApprovedException(mixed $value): bool
    {
        return is_array($value)
            && (($value['approved_exception'] ?? false) === true)
            && is_string($value['exception_reference'] ?? null)
            && $value['exception_reference'] !== '';
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return list<string>
     */
    private function listOfStrings(array $value): array
    {
        $values = array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', array_values($value));
        $values = array_values(array_filter($values, static fn (string $item): bool => $item !== ''));

        return array_values(array_unique($values));
    }

    /** @param array<string, mixed> $value */
    private function containsForbiddenMourabahaSemantics(array $value): bool
    {
        foreach ($value as $key => $item) {
            if ($this->tokenForbidden($key)) {
                return true;
            }
            if (is_string($item) && $this->tokenForbidden($item)) {
                return true;
            }
            if (is_array($item) && $this->containsForbiddenMourabahaSemantics($this->stringKeyedArrayOrEmpty($item))) {
                return true;
            }
        }

        return false;
    }

    private function tokenForbidden(string $token): bool
    {
        $normalizedToken = preg_replace('/[^a-z0-9]+/i', '_', $token);
        if (! is_string($normalizedToken)) {
            return false;
        }
        $normalized = strtolower(trim($normalizedToken));
        if ($normalized === '') {
            return false;
        }

        foreach (self::FORBIDDEN_MOURABAHA_SEMANTIC_TOKENS as $forbidden) {
            if (str_contains($normalized, $forbidden)) {
                return true;
            }
        }

        return false;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function metadataPayload(string $code, array $metadata): array
    {
        return [
            'code' => $code,
            'family_kind' => $metadata['family_kind'],
            'status' => 'active',
            'display_name' => $metadata['display_name'],
            'display_name_translations' => [
                'en' => $metadata['display_name'],
            ],
            'required_fields_schema' => [
                'required' => $metadata['required_fields'],
            ],
            'workflow_states' => $metadata['workflow_states'],
            'evidence_rules' => $metadata['evidence_rules'],
            'accounting_events' => $metadata['accounting_events'],
            'screening_rules' => $metadata['screening_rules'],
            'reporting_category' => $metadata['reporting_category'],
            'readiness_checklist_template' => $metadata['readiness_checklist'],
        ];
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyedArrayOrEmpty(array $value): array
    {
        $normalized = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }
            $normalized[$key] = $item;
        }

        return $normalized;
    }
}
