<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Application\JournalEntries\CreateJournalEntryReversal;
use App\Http\Controllers\BaseController;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\AccountingDay\AccountingDayGuard;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class IslamicFinancingWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly IslamicApprovalWorkflowService $approvalWorkflow,
        private readonly IslamicComplianceCaseService $complianceCases,
        private readonly IslamicScreeningPolicyService $screening,
        private readonly IslamicInterestGuardPolicy $interestGuard,
        private readonly IslamicMappingValidationService $mappingValidation,
        private readonly IslamicProductFamilyRegistry $productFamilies,
        private readonly CreateJournalEntryReversal $createJournalEntryReversal,
        private readonly IslamicSalamGoodsWorkflow $salamGoods,
        private readonly IslamicIstisnaaProjectWorkflow $istisnaaProjects,
        private readonly AccountingDayGuard $accountingDayGuard,
    ) {}

    public function storeFinancing(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'client_public_id' => ['required', 'string', 'exists:clients,public_id'],
            'agency_public_id' => ['required', 'string', 'exists:agencies,public_id'],
            'product_public_id' => ['required', 'string', 'exists:islamic_products,public_id'],
            'contract_type' => ['required', Rule::in(IslamicProductFamilyRegistry::supportedContractTypes())],
            'mourabaha_request_public_id' => ['sometimes', 'nullable', 'string', 'exists:islamic_mourabaha_requests,public_id'],
            'contract_template_public_id' => ['sometimes', 'nullable', 'string', 'exists:islamic_contract_templates,public_id'],
            'template_language_code' => ['sometimes', 'nullable', 'string', 'max:8'],
            'allow_template_language_fallback' => ['sometimes', 'boolean'],
            'purchase_cost_minor' => ['required', 'integer', 'min:1'],
            'allowed_costs_minor' => ['sometimes', 'integer', 'min:0'],
            'markup_minor' => ['required', 'integer', 'min:0'],
            'declared_sale_price_minor' => ['sometimes', 'integer', 'min:1'],
            'supplier_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(['XAF'])],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'late_payment_treatment' => ['sometimes', 'nullable', 'string', 'max:64'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        try {
            $this->interestGuard->assertLatePaymentTreatmentAllowed(is_string($validated['late_payment_treatment'] ?? null) ? $validated['late_payment_treatment'] : null);
        } catch (InvalidArgumentException $exception) {
            $this->securityAudit->record('islamic.interest_guard.late_payment_treatment_rejected', actor: $actor, properties: [
                'reason' => $exception->getMessage(),
            ], request: $request);

            return $this->respondUnprocessable(errors: ['islamic_interest_guardrails' => [$exception->getMessage()]]);
        }

        $productPublicId = (string) $validated['product_public_id'];
        $usability = $this->approvalWorkflow->isUsableForNewActions(
            IslamicApprovalStateMachine::SUBJECT_PRODUCT,
            $productPublicId,
        );
        if (! $usability['ok']) {
            $blockers = $this->complianceCases->activeBlockerFailures(
                blockerType: IslamicComplianceCaseService::BLOCKER_PRODUCT_ACTIVATION,
                targetSubjectType: IslamicComplianceCaseService::SUBJECT_PRODUCT,
                targetSubjectPublicId: $productPublicId,
            );
            if ($blockers !== []) {
                $this->securityAudit->record('islamic.compliance_case.use_blocked', actor: $actor, properties: [
                    'subject_type' => IslamicApprovalStateMachine::SUBJECT_PRODUCT,
                    'subject_public_id' => $productPublicId,
                    'blockers' => $blockers,
                ], request: $request);
            }
            $this->securityAudit->record('islamic.approval.use_blocked', actor: $actor, properties: [
                'subject_type' => IslamicApprovalStateMachine::SUBJECT_PRODUCT,
                'subject_public_id' => $productPublicId,
                'state' => $usability['state'],
                'reasons' => $usability['reasons'],
                'blockers' => $blockers,
            ], request: $request);

            return $this->respondUnprocessable(errors: [
                'islamic_financing' => ['Islamic product is not usable for new financing: '.implode(' ', $usability['reasons'])],
                'compliance_blockers' => $blockers,
            ]);
        }

        $raceBlocked = null;

        try {
            $financingPublicId = DB::transaction(function () use ($validated, $productPublicId, &$raceBlocked, $actor): string {
                $product = DB::table('islamic_products')->where('public_id', $productPublicId)->first();
                if (! is_object($product)) {
                    throw new InvalidArgumentException('Islamic product is invalid.');
                }

                // Re-check usability under a row lock to close the TOCTOU window
                // between the pre-transaction gate and the financing insert.
                $lockedUsability = $this->approvalWorkflow->isUsableForNewActionsLocked(
                    IslamicApprovalStateMachine::SUBJECT_PRODUCT,
                    $productPublicId,
                );
                if (! $lockedUsability['ok']) {
                    $raceBlocked = $lockedUsability;
                    throw new InvalidArgumentException('Islamic product is not usable for new financing: '.implode(' ', $lockedUsability['reasons']));
                }

                $client = DB::table('clients')->where('public_id', (string) $validated['client_public_id'])->first(['id', 'agency_id']);
                if (! is_object($client) || ! is_numeric($client->id) || ! is_numeric($client->agency_id)) {
                    throw new InvalidArgumentException('Client is invalid.');
                }
                $clientId = (int) $client->id;

                $agencyId = $this->idByPublicId('agencies', $validated['agency_public_id']);
                if ($agencyId === null) {
                    throw new InvalidArgumentException('Agency is invalid.');
                }
                if ((int) $client->agency_id !== $agencyId) {
                    throw new InvalidArgumentException('Client must belong to the financing agency.');
                }

                $productAgencyId = $this->rowNullableInt($product, 'agency_id');
                if ($productAgencyId !== null && $productAgencyId !== $agencyId) {
                    throw new InvalidArgumentException('Islamic product must belong to the financing agency or be global.');
                }

                $purchaseCost = (int) $validated['purchase_cost_minor'];
                $allowedCosts = (int) ($validated['allowed_costs_minor'] ?? 0);
                $markup = (int) $validated['markup_minor'];
                $salePrice = $purchaseCost + $allowedCosts + $markup;
                $declaredSalePrice = isset($validated['declared_sale_price_minor']) && is_numeric($validated['declared_sale_price_minor'])
                    ? (int) $validated['declared_sale_price_minor']
                    : null;
                if ($declaredSalePrice !== null && $declaredSalePrice !== $salePrice) {
                    throw new InvalidArgumentException('Declared sale price must equal purchase_cost + allowed_costs + markup.');
                }
                $contractType = (string) $validated['contract_type'];
                if ($this->productFamilies->familyKindFor($contractType) !== 'financing') {
                    throw new InvalidArgumentException('Financing contract type must be a financing family.');
                }
                if ($contractType !== $this->rowString($product, 'contract_type')) {
                    throw new InvalidArgumentException('Financing contract type must match the approved Islamic product.');
                }
                $familyCode = IslamicProductFamilyRegistry::familyForContractType($contractType);
                if (! is_string($familyCode) || $familyCode === '') {
                    throw new InvalidArgumentException('Islamic financing family is invalid.');
                }
                $templateResolution = $this->resolveTemplateForOrigination(
                    familyCode: $familyCode,
                    explicitTemplatePublicId: is_string($validated['contract_template_public_id'] ?? null) ? $validated['contract_template_public_id'] : null,
                    preferredLanguageCode: is_string($validated['template_language_code'] ?? null) ? $validated['template_language_code'] : null,
                    allowLanguageFallback: (bool) ($validated['allow_template_language_fallback'] ?? false),
                );
                $template = $templateResolution['template'];
                if ($templateResolution['fallback_used'] === true) {
                    $this->securityAudit->record('islamic.contract_template.language_fallback_used', actor: $actor, properties: [
                        'family_code' => $familyCode,
                        'preferred_language_code' => $templateResolution['preferred_language_code'],
                        'selected_language_code' => $templateResolution['selected_language_code'],
                        'template_public_id' => $this->rowString($template, 'public_id'),
                    ]);
                }
                $templateUsability = $this->approvalWorkflow->isUsableForNewActionsLocked(
                    IslamicApprovalStateMachine::SUBJECT_CONTRACT_TEMPLATE,
                    $this->rowString($template, 'public_id'),
                );
                if (! $templateUsability['ok']) {
                    throw new InvalidArgumentException('Islamic contract template is not usable for origination: '.implode(' ', $templateUsability['reasons']));
                }
                $currency = is_string($validated['currency'] ?? null) && $validated['currency'] !== '' ? $validated['currency'] : 'XAF';

                $mourabahaRequestId = null;
                $mourabahaRequestPublicId = is_string($validated['mourabaha_request_public_id'] ?? null)
                    ? $validated['mourabaha_request_public_id']
                    : null;
                if ($mourabahaRequestPublicId !== null && $mourabahaRequestPublicId !== '') {
                    $mourabahaRequest = DB::table('islamic_mourabaha_requests')
                        ->where('public_id', $mourabahaRequestPublicId)
                        ->lockForUpdate()
                        ->first(['id', 'client_id', 'agency_id', 'islamic_product_id']);
                    if (! is_object($mourabahaRequest) || ! is_numeric($mourabahaRequest->id)) {
                        throw new InvalidArgumentException('Mourabaha request is invalid.');
                    }
                    if (is_numeric($mourabahaRequest->client_id) && (int) $mourabahaRequest->client_id !== $clientId) {
                        throw new InvalidArgumentException('Mourabaha request client does not match financing client.');
                    }
                    if (is_numeric($mourabahaRequest->agency_id) && (int) $mourabahaRequest->agency_id !== $agencyId) {
                        throw new InvalidArgumentException('Mourabaha request agency does not match financing agency.');
                    }
                    if (is_numeric($mourabahaRequest->islamic_product_id) && (int) $mourabahaRequest->islamic_product_id !== $this->rowInt($product, 'id')) {
                        throw new InvalidArgumentException('Mourabaha request product does not match financing product.');
                    }
                    $mourabahaRequestId = (int) $mourabahaRequest->id;
                }

                $publicId = (string) Str::ulid();
                DB::table('islamic_financings')->insert([
                    'public_id' => $publicId,
                    'client_id' => $clientId,
                    'agency_id' => $agencyId,
                    'islamic_product_id' => $this->rowInt($product, 'id'),
                    'loan_id' => null,
                    'contract_number' => 'IF-'.Str::upper(Str::random(10)),
                    'contract_type' => $contractType,
                    'financed_amount_minor' => $purchaseCost,
                    'purchase_cost_minor' => $purchaseCost,
                    'allowed_costs_minor' => $allowedCosts,
                    'markup_minor' => $markup,
                    'sale_price_minor' => $salePrice,
                    'supplier_name' => $this->nullableString($validated['supplier_name'] ?? null),
                    'currency' => $currency,
                    'starts_on' => $this->nullableString($validated['starts_on'] ?? null),
                    'ends_on' => $this->nullableString($validated['ends_on'] ?? null),
                    'status' => 'draft',
                    'terms' => json_encode([
                        'contract_template_public_id' => $this->rowString($template, 'public_id'),
                        'contract_template_code' => $this->rowString($template, 'template_code'),
                        'contract_template_version' => $this->rowInt($template, 'version'),
                        'contract_template_language_code' => $this->rowString($template, 'language_code'),
                        'late_payment_treatment' => is_string($validated['late_payment_treatment'] ?? null) ? $validated['late_payment_treatment'] : null,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($mourabahaRequestId !== null) {
                    DB::table('islamic_mourabaha_requests')
                        ->where('id', $mourabahaRequestId)
                        ->update([
                            'islamic_financing_id' => DB::table('islamic_financings')->where('public_id', $publicId)->value('id'),
                            'request_status' => 'financing_created',
                            'updated_at' => now(),
                        ]);
                }

                $templateSnapshot = [
                    'template_public_id' => $this->rowString($template, 'public_id'),
                    'template_code' => $this->rowString($template, 'template_code'),
                    'version' => $this->rowInt($template, 'version'),
                    'language_code' => $this->rowString($template, 'language_code'),
                    'family_code' => $this->rowString($template, 'family_code'),
                    'effective_from' => $this->nullableString(((array) $template)['effective_from'] ?? null),
                    'effective_to' => $this->nullableString(((array) $template)['effective_to'] ?? null),
                    'fields_schema' => $this->decodeJson(((array) $template)['fields_schema'] ?? null),
                    'commercial_terms_schema' => $this->decodeJson(((array) $template)['commercial_terms_schema'] ?? null),
                    'legal_signoff_ref' => $this->nullableString(((array) $template)['legal_signoff_ref'] ?? null),
                    'sharia_signoff_ref' => $this->nullableString(((array) $template)['sharia_signoff_ref'] ?? null),
                ];
                $resolvedTermsSnapshot = [
                    'contract_type' => $contractType,
                    'currency' => $currency,
                    'purchase_cost_minor' => $purchaseCost,
                    'allowed_costs_minor' => $allowedCosts,
                    'markup_minor' => $markup,
                    'sale_price_minor' => $salePrice,
                    'supplier_name' => $this->nullableString($validated['supplier_name'] ?? null),
                    'late_payment_treatment' => is_string($validated['late_payment_treatment'] ?? null) ? $validated['late_payment_treatment'] : null,
                ];
                $snapshotPayload = [
                    'template' => $templateSnapshot,
                    'terms' => $resolvedTermsSnapshot,
                ];
                $snapshotJson = json_encode($snapshotPayload, JSON_THROW_ON_ERROR);

                DB::table('islamic_contract_template_snapshots')->insert([
                    'public_id' => (string) Str::ulid(),
                    'contract_subject_type' => 'islamic_financing',
                    'contract_subject_public_id' => $publicId,
                    'template_public_id' => $this->rowString($template, 'public_id'),
                    'template_code' => $this->rowString($template, 'template_code'),
                    'template_version' => $this->rowInt($template, 'version'),
                    'language_code' => $this->rowString($template, 'language_code'),
                    'template_snapshot' => json_encode($templateSnapshot, JSON_THROW_ON_ERROR),
                    'resolved_terms_snapshot' => json_encode($resolvedTermsSnapshot, JSON_THROW_ON_ERROR),
                    'snapshot_hash' => hash('sha256', $snapshotJson),
                    'created_by_user_id' => $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->securityAudit->record('islamic.contract_template.snapshot_stored', actor: $actor, properties: [
                    'contract_subject_type' => 'islamic_financing',
                    'contract_subject_public_id' => $publicId,
                    'template_public_id' => $this->rowString($template, 'public_id'),
                    'template_code' => $this->rowString($template, 'template_code'),
                    'template_version' => $this->rowInt($template, 'version'),
                    'snapshot_hash' => hash('sha256', $snapshotJson),
                ]);

                return $publicId;
            });
        } catch (InvalidArgumentException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'template')) {
                $this->securityAudit->record('islamic.contract_template.use_blocked', actor: $actor, properties: [
                    'product_public_id' => $productPublicId,
                    'reason' => $exception->getMessage(),
                ], request: $request);
            }
            if (is_array($raceBlocked)) {
                $raceBlockers = $this->complianceCases->activeBlockerFailures(
                    blockerType: IslamicComplianceCaseService::BLOCKER_PRODUCT_ACTIVATION,
                    targetSubjectType: IslamicComplianceCaseService::SUBJECT_PRODUCT,
                    targetSubjectPublicId: $productPublicId,
                );
                if ($raceBlockers !== []) {
                    $this->securityAudit->record('islamic.compliance_case.use_blocked', actor: $actor, properties: [
                        'subject_type' => IslamicApprovalStateMachine::SUBJECT_PRODUCT,
                        'subject_public_id' => $productPublicId,
                        'blockers' => $raceBlockers,
                        'race' => true,
                    ], request: $request);
                }
                $this->securityAudit->record('islamic.approval.use_blocked', actor: $actor, properties: [
                    'subject_type' => IslamicApprovalStateMachine::SUBJECT_PRODUCT,
                    'subject_public_id' => $productPublicId,
                    'state' => $raceBlocked['state'],
                    'reasons' => $raceBlocked['reasons'],
                    'race' => true,
                ], request: $request);
            }

            return $this->respondUnprocessable(errors: ['islamic_financing' => [$exception->getMessage()]]);
        }

        $row = DB::table('islamic_financings')->where('public_id', $financingPublicId)->first();
        if (! is_object($row)) {
            return $this->respondUnprocessable(errors: ['islamic_financing' => [__('Financing could not be reloaded.')]]);
        }

        $this->securityAudit->record('islamic.financing.created', actor: $actor, properties: [
            'financing_public_id' => $this->rowString($row, 'public_id'),
            'contract_type' => $this->rowString($row, 'contract_type'),
        ], request: $request);

        return $this->respondCreated($this->financingPayload($row), 'Islamic financing draft created');
    }

    public function storeFinancingAsset(Request $request, string $financingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'asset_type' => ['required', 'string', 'max:64'],
            'asset_category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'description' => ['required', 'string', 'max:2000'],
            'supplier_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'supplier_reference' => ['sometimes', 'nullable', 'string', 'max:128'],
            'purchase_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'acquisition_cost_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'condition_status' => ['sometimes', 'nullable', 'string', 'max:64'],
            'document_bundle' => ['sometimes', 'nullable', 'array'],
            'customer_request_ref' => ['sometimes', 'nullable', 'string', 'max:128'],
            'screening_result_public_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($financingPublicId, $validated, $actor): object {
                $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->lockForUpdate()->first();
                if (! is_object($financing)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                if ($this->rowString($financing, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Assets can only be added to draft financings.');
                }

                $existingPurchaseEvidence = DB::table('islamic_mourabaha_purchase_evidences')
                    ->where('islamic_financing_id', $this->rowInt($financing, 'id'))
                    ->whereIn('institution_control_status', ['controlled_by_institution', 'owned_by_institution'])
                    ->orderByDesc('id')
                    ->first(['public_id', 'institution_control_status']);

                $initialLifecycle = IslamicFinancedAssetStateMachine::STATUS_REQUESTED;
                $initialOwnership = 'pending';
                $autoInitScreeningResultPublicId = null;
                if (is_object($existingPurchaseEvidence)) {
                    // Run acceptance screening before initializing to purchased — IF-040 Phase 4 invariant.
                    $supplierFlagsForInit = [];
                    $supplierNameForInit = is_string($validated['supplier_name'] ?? null) && $validated['supplier_name'] !== '' ? $validated['supplier_name'] : null;
                    $supplierReferenceForInit = is_string($validated['supplier_reference'] ?? null) && $validated['supplier_reference'] !== '' ? $validated['supplier_reference'] : null;
                    if ($supplierNameForInit !== null) {
                        $supplierFlagsForInit[] = strtolower(trim($supplierNameForInit));
                    }
                    if ($supplierReferenceForInit !== null) {
                        $supplierFlagsForInit[] = strtolower(trim($supplierReferenceForInit));
                    }
                    $financingContractType = $this->rowString($financing, 'contract_type');
                    $canonicalFamilyForInit = IslamicProductFamilyRegistry::familyForContractType($financingContractType) ?? $financingContractType;
                    $tentativeAssetPublicId = (string) Str::ulid();
                    $autoInitFacts = [
                        'scope_type' => 'product_family',
                        'scope_value' => $canonicalFamilyForInit,
                        'asset_public_id' => $tentativeAssetPublicId,
                        'supplier_name' => $supplierNameForInit,
                        'supplier_reference' => $supplierReferenceForInit,
                        'supplier_flags' => $supplierFlagsForInit,
                        'target_status' => IslamicFinancedAssetStateMachine::STATUS_PURCHASED,
                    ];
                    $autoInitScreening = $this->screening->evaluate(
                        subjectType: 'islamic_financed_asset',
                        subjectPublicId: $tentativeAssetPublicId,
                        contextType: 'asset_acceptance',
                        facts: $autoInitFacts,
                        actor: $actor,
                        strictPolicy: false,
                        overrideExceptionSubjectPublicId: null,
                    );
                    $autoInitStatus = is_string($autoInitScreening['result'] ?? null) ? $autoInitScreening['result'] : 'not_applicable';
                    $autoInitScreeningResultPublicId = is_string($autoInitScreening['public_id'] ?? null) && $autoInitScreening['public_id'] !== '' ? $autoInitScreening['public_id'] : null;
                    if ($autoInitStatus === 'fail') {
                        throw new InvalidArgumentException('Asset acceptance blocked by screening result; cannot auto-initialize at purchased status.');
                    }
                    if ($autoInitStatus === 'manual_review') {
                        throw new InvalidArgumentException('Asset acceptance requires manual compliance review before auto-initialization at purchased.');
                    }
                    $initialLifecycle = IslamicFinancedAssetStateMachine::STATUS_PURCHASED;
                    $initialOwnership = 'owned_by_institution';
                }

                $id = DB::table('islamic_financed_assets')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financing_id' => $this->rowInt($financing, 'id'),
                    'asset_type' => (string) $validated['asset_type'],
                    'asset_category' => is_string($validated['asset_category'] ?? null) && $validated['asset_category'] !== '' ? $validated['asset_category'] : null,
                    'description' => (string) $validated['description'],
                    'supplier_name' => is_string($validated['supplier_name'] ?? null) && $validated['supplier_name'] !== '' ? $validated['supplier_name'] : null,
                    'supplier_reference' => is_string($validated['supplier_reference'] ?? null) && $validated['supplier_reference'] !== '' ? $validated['supplier_reference'] : null,
                    'purchase_amount_minor' => is_numeric($validated['purchase_amount_minor'] ?? null) ? (int) $validated['purchase_amount_minor'] : null,
                    'acquisition_cost_minor' => is_numeric($validated['acquisition_cost_minor'] ?? null) ? (int) $validated['acquisition_cost_minor'] : null,
                    'currency' => is_string($validated['currency'] ?? null) && $validated['currency'] !== '' ? $validated['currency'] : 'XAF',
                    'location' => is_string($validated['location'] ?? null) && $validated['location'] !== '' ? $validated['location'] : null,
                    'condition_status' => is_string($validated['condition_status'] ?? null) && $validated['condition_status'] !== '' ? $validated['condition_status'] : null,
                    'document_bundle' => isset($validated['document_bundle']) && is_array($validated['document_bundle']) ? json_encode($validated['document_bundle'], JSON_THROW_ON_ERROR) : null,
                    'customer_request_ref' => is_string($validated['customer_request_ref'] ?? null) && $validated['customer_request_ref'] !== '' ? $validated['customer_request_ref'] : null,
                    'screening_result_public_id' => $autoInitScreeningResultPublicId
                        ?? (is_string($validated['screening_result_public_id'] ?? null) && $validated['screening_result_public_id'] !== '' ? $validated['screening_result_public_id'] : null),
                    'ownership_status' => $initialOwnership,
                    'lifecycle_status' => $initialLifecycle,
                    'metadata' => isset($validated['metadata']) ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if (is_object($existingPurchaseEvidence)) {
                    DB::table('islamic_financed_asset_transitions')->insert([
                        'public_id' => (string) Str::ulid(),
                        'islamic_financed_asset_id' => $id,
                        'from_status' => IslamicFinancedAssetStateMachine::STATUS_REQUESTED,
                        'to_status' => IslamicFinancedAssetStateMachine::STATUS_PURCHASED,
                        'reason_code' => 'mourabaha_purchase_evidence_pre_existing',
                        'reason_note' => 'Asset created after Mourabaha purchase evidence already captured; initialized at purchased.',
                        'product_family' => IslamicProductFamilyRegistry::familyForContractType($this->rowString($financing, 'contract_type')) ?? $this->rowString($financing, 'contract_type'),
                        'screening_result_public_id' => $autoInitScreeningResultPublicId,
                        'compliance_case_public_id' => null,
                        'evidence_refs' => json_encode([
                            'purchase_evidence' => $this->rowString($existingPurchaseEvidence, 'public_id'),
                            'institution_control_status' => $this->rowString($existingPurchaseEvidence, 'institution_control_status'),
                        ], JSON_THROW_ON_ERROR),
                        'context_snapshot' => json_encode([
                            'islamic_financing_id' => $this->rowInt($financing, 'id'),
                        ], JSON_THROW_ON_ERROR),
                        'actor_user_id' => $actor->id,
                        'transitioned_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $row = DB::table('islamic_financed_assets')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Financed asset could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_financed_asset' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.asset.registered', actor: $actor, properties: [
            'financing_public_id' => $financingPublicId,
            'asset_public_id' => $this->rowString($row, 'public_id'),
            'ownership_status' => $this->rowString($row, 'ownership_status'),
            'lifecycle_status' => $this->rowString($row, 'lifecycle_status'),
        ], request: $request);

        return $this->respondCreated($this->assetPayload($row), 'Financed asset registered');
    }

    public function transitionFinancingAsset(Request $request, string $assetPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'to_status' => ['required', 'string', 'in:'.implode(',', IslamicFinancedAssetStateMachine::STATUSES)],
            'evidence' => ['sometimes', 'array'],
            'reason_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'reason_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'screening_result_public_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'override_exception_subject_public_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $toStatus = (string) $validated['to_status'];
        $evidence = is_array($validated['evidence'] ?? null) ? $validated['evidence'] : [];
        $reasonCode = is_string($validated['reason_code'] ?? null) && $validated['reason_code'] !== '' ? $validated['reason_code'] : null;
        $reasonNote = is_string($validated['reason_note'] ?? null) && $validated['reason_note'] !== '' ? $validated['reason_note'] : null;
        $screeningResultRef = is_string($validated['screening_result_public_id'] ?? null) && $validated['screening_result_public_id'] !== '' ? $validated['screening_result_public_id'] : null;
        $overrideExceptionRef = is_string($validated['override_exception_subject_public_id'] ?? null) && $validated['override_exception_subject_public_id'] !== '' ? $validated['override_exception_subject_public_id'] : null;

        try {
            $result = DB::transaction(function () use ($assetPublicId, $toStatus, $evidence, $reasonCode, $reasonNote, $screeningResultRef, $overrideExceptionRef, $actor, $request): array {
                $asset = DB::table('islamic_financed_assets')->where('public_id', $assetPublicId)->lockForUpdate()->first();
                if (! is_object($asset)) {
                    throw new InvalidArgumentException('Financed asset is invalid.');
                }
                $fromStatus = $this->rowString($asset, 'lifecycle_status');
                IslamicFinancedAssetStateMachine::assertTransitionAllowed($fromStatus, $toStatus);
                IslamicFinancedAssetStateMachine::assertEvidenceComplete($toStatus, $evidence);
                $this->assertEvidenceDocumentsExist($evidence);

                $financingId = $this->rowInt($asset, 'islamic_financing_id');
                $financing = DB::table('islamic_financings')->where('id', $financingId)->first();
                if (! is_object($financing)) {
                    throw new InvalidArgumentException('Asset is not linked to a valid financing.');
                }

                $productFamily = $this->rowString($financing, 'contract_type');
                $canonicalFamily = $this->productFamilies::familyForContractType($productFamily) ?? $productFamily;

                if (
                    $toStatus === IslamicFinancedAssetStateMachine::STATUS_TRANSFERRED
                    && $canonicalFamily === 'ijara_wa_iqtina'
                ) {
                    $this->securityAudit->record('islamic.ijara.transfer_direct_mutation_blocked', actor: $actor, properties: [
                        'asset_public_id' => $assetPublicId,
                        'financing_public_id' => $this->rowString($financing, 'public_id'),
                        'from_status' => $fromStatus,
                        'requested_to_status' => $toStatus,
                    ], request: $request);
                    throw new InvalidArgumentException('Direct transfer mutation rejected. Use the Ijara wa Iqtina transfer workflow.');
                }

                $screeningCasePublicId = null;
                $screeningResultPublicId = $screeningResultRef;
                if (IslamicFinancedAssetStateMachine::requiresAcceptanceScreening($toStatus)) {
                    $supplierFlags = [];
                    $supplierName = $this->rowNullableString($asset, 'supplier_name');
                    $supplierReference = $this->rowNullableString($asset, 'supplier_reference');
                    if (is_string($supplierName) && $supplierName !== '') {
                        $supplierFlags[] = strtolower(trim($supplierName));
                    }
                    if (is_string($supplierReference) && $supplierReference !== '') {
                        $supplierFlags[] = strtolower(trim($supplierReference));
                    }
                    $facts = [
                        'scope_type' => 'product_family',
                        'scope_value' => $canonicalFamily,
                        'asset_public_id' => $assetPublicId,
                        'supplier_name' => $supplierName,
                        'supplier_reference' => $supplierReference,
                        'supplier_flags' => $supplierFlags,
                        'target_status' => $toStatus,
                    ];
                    $screeningOutcome = $this->screening->evaluate(
                        subjectType: 'islamic_financed_asset',
                        subjectPublicId: $assetPublicId,
                        contextType: 'asset_acceptance',
                        facts: $facts,
                        actor: $actor,
                        strictPolicy: false,
                        overrideExceptionSubjectPublicId: $overrideExceptionRef,
                    );
                    $resultStatus = is_string($screeningOutcome['result'] ?? null) ? $screeningOutcome['result'] : 'not_applicable';
                    $screeningResultPublicId = is_string($screeningOutcome['public_id'] ?? null) && $screeningOutcome['public_id'] !== '' ? $screeningOutcome['public_id'] : $screeningResultPublicId;
                    $screeningCasePublicId = is_string($screeningOutcome['review_case_public_id'] ?? null) && $screeningOutcome['review_case_public_id'] !== '' ? $screeningOutcome['review_case_public_id'] : null;

                    if ($resultStatus === 'fail') {
                        $this->securityAudit->record('islamic.asset.acceptance_screening_blocked', actor: $actor, properties: [
                            'asset_public_id' => $assetPublicId,
                            'product_family' => $canonicalFamily,
                            'target_status' => $toStatus,
                            'screening_result_public_id' => $screeningResultPublicId,
                        ], request: $request);
                        $this->securityAudit->record('islamic.asset.transition_blocked', actor: $actor, properties: [
                            'asset_public_id' => $assetPublicId,
                            'from_status' => $fromStatus,
                            'to_status' => $toStatus,
                            'reason' => 'acceptance_screening_failed',
                        ], request: $request);
                        throw new InvalidArgumentException('Asset acceptance transition blocked by screening result.');
                    }
                    if ($resultStatus === 'manual_review') {
                        $this->securityAudit->record('islamic.asset.transition_blocked', actor: $actor, properties: [
                            'asset_public_id' => $assetPublicId,
                            'from_status' => $fromStatus,
                            'to_status' => $toStatus,
                            'reason' => 'acceptance_screening_manual_review',
                            'compliance_case_public_id' => $screeningCasePublicId,
                        ], request: $request);
                        throw new InvalidArgumentException('Asset acceptance transition requires manual compliance review.');
                    }
                }

                $transitionId = DB::table('islamic_financed_asset_transitions')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financed_asset_id' => $this->rowInt($asset, 'id'),
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'reason_code' => $reasonCode,
                    'reason_note' => $reasonNote,
                    'product_family' => $canonicalFamily,
                    'screening_result_public_id' => $screeningResultPublicId,
                    'compliance_case_public_id' => $screeningCasePublicId,
                    'evidence_refs' => json_encode($evidence, JSON_THROW_ON_ERROR),
                    'context_snapshot' => json_encode([
                        'financing_public_id' => $this->rowString($financing, 'public_id'),
                        'contract_type' => $productFamily,
                    ], JSON_THROW_ON_ERROR),
                    'actor_user_id' => $actor->id,
                    'transitioned_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $assetUpdates = ['lifecycle_status' => $toStatus, 'updated_at' => now()];
                if ($screeningResultPublicId !== null) {
                    $assetUpdates['screening_result_public_id'] = $screeningResultPublicId;
                }
                if (
                    $toStatus === IslamicFinancedAssetStateMachine::STATUS_PURCHASED
                    || $toStatus === IslamicFinancedAssetStateMachine::STATUS_CONTROLLED
                    || $toStatus === IslamicFinancedAssetStateMachine::STATUS_DELIVERED
                ) {
                    $assetUpdates['ownership_status'] = 'owned_by_institution';
                }
                if ($toStatus === IslamicFinancedAssetStateMachine::STATUS_LEASED) {
                    $assetUpdates['ownership_status'] = 'leased_to_customer';
                }
                if ($toStatus === IslamicFinancedAssetStateMachine::STATUS_TRANSFERRED) {
                    $assetUpdates['ownership_status'] = 'owned_by_customer';
                }
                if ($toStatus === IslamicFinancedAssetStateMachine::STATUS_RETURNED) {
                    $assetUpdates['ownership_status'] = 'returned_by_customer';
                }
                DB::table('islamic_financed_assets')->where('id', $this->rowInt($asset, 'id'))->update($assetUpdates);

                $row = DB::table('islamic_financed_assets')->where('id', $this->rowInt($asset, 'id'))->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Asset could not be reloaded after transition.');
                }
                $transition = DB::table('islamic_financed_asset_transitions')->where('id', $transitionId)->first();
                if (! is_object($transition)) {
                    throw new InvalidArgumentException('Asset transition record could not be reloaded.');
                }

                return ['asset' => $row, 'transition' => $transition, 'screening_result_public_id' => $screeningResultPublicId, 'compliance_case_public_id' => $screeningCasePublicId];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_financed_asset' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.asset.transitioned', actor: $actor, properties: [
            'asset_public_id' => $assetPublicId,
            'from_status' => $this->rowString($result['transition'], 'from_status'),
            'to_status' => $this->rowString($result['transition'], 'to_status'),
            'transition_public_id' => $this->rowString($result['transition'], 'public_id'),
        ], request: $request);

        return $this->respondSuccess([
            'asset' => $this->assetPayload($result['asset']),
            'transition' => $this->assetTransitionPayload($result['transition']),
        ], 'Asset transitioned');
    }

    public function showFinancedAsset(Request $request, string $assetPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $asset = DB::table('islamic_financed_assets')->where('public_id', $assetPublicId)->first();
        if (! is_object($asset)) {
            return $this->respondNotFound('Financed asset not found.');
        }

        return $this->respondSuccess($this->assetPayload($asset));
    }

    public function showFinancedAssetTimeline(Request $request, string $assetPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $asset = DB::table('islamic_financed_assets')->where('public_id', $assetPublicId)->first();
        if (! is_object($asset)) {
            return $this->respondNotFound('Financed asset not found.');
        }
        $rows = $this->financedAssetTimelineItems($this->rowInt($asset, 'id'));
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = mb_strtolower(trim($search));
            $rows = array_values(array_filter($rows, static function (array $row) use ($term): bool {
                $haystack = mb_strtolower(json_encode($row, JSON_THROW_ON_ERROR));

                return str_contains($haystack, $term);
            }));
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);
        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return $this->respondSuccess([
            'asset_public_id' => $assetPublicId,
            'current_status' => $this->rowString($asset, 'lifecycle_status'),
            'timeline_events' => $slice,
        ], 'Financed asset timeline retrieved', meta: [
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil(max(1, $total) / $perPage),
            ],
        ]);
    }

    public function storeInstallments(Request $request, string $financingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'installments' => ['required', 'array', 'min:1'],
            'installments.*.due_on' => ['required', 'date'],
            'installments.*.amount_minor' => ['required', 'integer', 'min:1'],
        ])->validate();

        try {
            $rows = DB::transaction(function () use ($financingPublicId, $validated): array {
                $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->lockForUpdate()->first();
                if (! is_object($financing)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                if ($this->rowString($financing, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Installments can only be added to draft financings.');
                }

                $financingId = $this->rowInt($financing, 'id');
                $currency = $this->rowString($financing, 'currency');

                $existingCount = DB::table('islamic_financing_installments')
                    ->where('islamic_financing_id', $financingId)
                    ->count();
                if ($existingCount > 0) {
                    throw new InvalidArgumentException('Installments have already been generated for this financing.');
                }

                $installmentsInput = is_array($validated['installments'] ?? null) ? $validated['installments'] : [];
                $totalAmount = 0;
                $createdRows = [];

                foreach ($installmentsInput as $i => $inst) {
                    if (! is_array($inst) || ! isset($inst['amount_minor'], $inst['due_on'])) {
                        continue;
                    }
                    $number = $i + 1;
                    $amount = is_numeric($inst['amount_minor']) ? (int) $inst['amount_minor'] : 0;
                    $totalAmount += $amount;

                    $id = DB::table('islamic_financing_installments')->insertGetId([
                        'public_id' => (string) Str::ulid(),
                        'islamic_financing_id' => $financingId,
                        'installment_number' => $number,
                        'due_on' => is_scalar($inst['due_on']) ? (string) $inst['due_on'] : '',
                        'amount_minor' => $amount,
                        'paid_amount_minor' => 0,
                        'currency' => $currency,
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $createdRows[] = DB::table('islamic_financing_installments')->where('id', $id)->first();
                }

                $salePrice = $this->rowInt($financing, 'sale_price_minor');
                if ($totalAmount !== $salePrice) {
                    throw new InvalidArgumentException(
                        'Total installment amount ('.$totalAmount.') must equal the sale price ('.$salePrice.').'
                    );
                }

                return $createdRows;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_financing_installment' => [$exception->getMessage()]]);
        }

        $payload = array_values(array_filter($rows, fn (mixed $row): bool => is_object($row)));
        $payload = array_map(fn (object $row): array => $this->installmentPayload($row), $payload);

        return $this->respondCreated(data: $payload, message: 'Financing installments created');
    }

    public function storeMourabahaRequest(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'client_public_id' => ['required', 'string', 'exists:clients,public_id'],
            'agency_public_id' => ['required', 'string', 'exists:agencies,public_id'],
            'product_public_id' => ['required', 'string', 'exists:islamic_products,public_id'],
            'financing_public_id' => ['sometimes', 'nullable', 'string', 'exists:islamic_financings,public_id'],
            'asset_type' => ['required', 'string', 'max:64'],
            'asset_description' => ['required', 'string', 'max:2000'],
            'supplier_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'requested_purchase_cost_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'request_context' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $client = DB::table('clients')->where('public_id', (string) $validated['client_public_id'])->first(['id', 'agency_id']);
        $product = DB::table('islamic_products')->where('public_id', (string) $validated['product_public_id'])->first(['id', 'contract_type']);
        $agencyId = $this->idByPublicId('agencies', $validated['agency_public_id']);
        if (! is_object($client) || ! is_numeric($client->id) || ! is_numeric($client->agency_id) || $agencyId === null || (int) $client->agency_id !== $agencyId) {
            return $this->respondUnprocessable(errors: ['islamic_mourabaha_request' => [__('Client must belong to request agency.')]]);
        }
        if (! is_object($product) || ! is_numeric($product->id) || ! is_string($product->contract_type) || IslamicProductFamilyRegistry::familyForContractType($product->contract_type) !== 'mourabaha') {
            return $this->respondUnprocessable(errors: ['islamic_mourabaha_request' => [__('Mourabaha request requires a Mourabaha product.')]]);
        }

        $financingId = null;
        $financingPublicId = is_string($validated['financing_public_id'] ?? null) ? $validated['financing_public_id'] : null;
        if ($financingPublicId !== null && $financingPublicId !== '') {
            $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->first(['id', 'client_id', 'agency_id', 'islamic_product_id']);
            if (! is_object($financing) || ! is_numeric($financing->id)) {
                return $this->respondUnprocessable(errors: ['islamic_mourabaha_request' => [__('Financing is invalid.')]]);
            }
            if ((int) $financing->client_id !== (int) $client->id || (int) $financing->agency_id !== $agencyId || (int) $financing->islamic_product_id !== (int) $product->id) {
                return $this->respondUnprocessable(errors: ['islamic_mourabaha_request' => [__('Financing does not align with request client/agency/product.')]]);
            }
            $financingId = (int) $financing->id;
        }

        $id = DB::table('islamic_mourabaha_requests')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => (int) $client->id,
            'agency_id' => $agencyId,
            'islamic_product_id' => (int) $product->id,
            'islamic_financing_id' => $financingId,
            'request_status' => 'draft',
            'asset_type' => (string) $validated['asset_type'],
            'asset_description' => (string) $validated['asset_description'],
            'requested_purchase_cost_minor' => is_numeric($validated['requested_purchase_cost_minor'] ?? null) ? (int) $validated['requested_purchase_cost_minor'] : null,
            'supplier_name' => $this->nullableString($validated['supplier_name'] ?? null),
            'request_context' => isset($validated['request_context']) ? json_encode($validated['request_context'], JSON_THROW_ON_ERROR) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('islamic_mourabaha_requests')->where('id', $id)->first();
        if (! is_object($row)) {
            return $this->respondUnprocessable(errors: ['islamic_mourabaha_request' => [__('Request could not be reloaded.')]]);
        }

        return $this->respondCreated($this->mourabahaRequestPayload($row), 'Mourabaha request captured');
    }

    public function storeMourabahaQuote(Request $request, string $requestPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'supplier_name' => ['required', 'string', 'max:255'],
            'quoted_purchase_cost_minor' => ['required', 'integer', 'min:1'],
            'quoted_allowed_costs_minor' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(['XAF'])],
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'is_selected' => ['sometimes', 'boolean'],
            'quote_context' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $requestRow = DB::table('islamic_mourabaha_requests')->where('public_id', $requestPublicId)->first(['id']);
        if (! is_object($requestRow) || ! is_numeric($requestRow->id)) {
            return $this->respondUnprocessable(errors: ['islamic_mourabaha_quote' => [__('Mourabaha request is invalid.')]]);
        }
        $requestId = (int) $requestRow->id;

        $quoteId = DB::transaction(function () use ($requestId, $validated): int {
            if (($validated['is_selected'] ?? false) === true) {
                DB::table('islamic_mourabaha_supplier_quotes')
                    ->where('mourabaha_request_id', $requestId)
                    ->update([
                        'is_selected' => false,
                        'updated_at' => now(),
                    ]);
            }

            return DB::table('islamic_mourabaha_supplier_quotes')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'mourabaha_request_id' => $requestId,
                'supplier_name' => (string) $validated['supplier_name'],
                'quoted_purchase_cost_minor' => (int) $validated['quoted_purchase_cost_minor'],
                'quoted_allowed_costs_minor' => (int) ($validated['quoted_allowed_costs_minor'] ?? 0),
                'currency' => is_string($validated['currency'] ?? null) && $validated['currency'] !== '' ? $validated['currency'] : 'XAF',
                'valid_until' => is_string($validated['valid_until'] ?? null) ? $validated['valid_until'] : null,
                'is_selected' => (bool) ($validated['is_selected'] ?? false),
                'quote_context' => isset($validated['quote_context']) ? json_encode($validated['quote_context'], JSON_THROW_ON_ERROR) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $row = DB::table('islamic_mourabaha_supplier_quotes')->where('id', $quoteId)->first();
        if (! is_object($row)) {
            return $this->respondUnprocessable(errors: ['islamic_mourabaha_quote' => [__('Quote could not be reloaded.')]]);
        }

        return $this->respondCreated($this->mourabahaQuotePayload($row), 'Mourabaha supplier quote captured');
    }

    public function approveMourabahaPurchase(Request $request, string $requestPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'supplier_quote_public_id' => ['required', 'string', 'exists:islamic_mourabaha_supplier_quotes,public_id'],
            'decision' => ['sometimes', Rule::in(['approved', 'rejected'])],
            'decision_context' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($requestPublicId, $validated, $actor): object {
                $requestRow = DB::table('islamic_mourabaha_requests')
                    ->where('public_id', $requestPublicId)
                    ->lockForUpdate()
                    ->first(['id']);
                if (! is_object($requestRow) || ! is_numeric($requestRow->id)) {
                    throw new InvalidArgumentException('Mourabaha request is invalid.');
                }
                $requestId = (int) $requestRow->id;

                $quote = DB::table('islamic_mourabaha_supplier_quotes')
                    ->where('public_id', (string) $validated['supplier_quote_public_id'])
                    ->where('mourabaha_request_id', $requestId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($quote)) {
                    throw new InvalidArgumentException('Supplier quote does not belong to request.');
                }
                $validUntil = $this->rowNullableString($quote, 'valid_until');
                if ($validUntil !== null && $validUntil < now()->toDateString()) {
                    throw new InvalidArgumentException('Supplier quote has expired and cannot be approved.');
                }

                DB::table('islamic_mourabaha_supplier_quotes')
                    ->where('mourabaha_request_id', $requestId)
                    ->update(['is_selected' => false, 'updated_at' => now()]);
                DB::table('islamic_mourabaha_supplier_quotes')
                    ->where('id', $this->rowInt($quote, 'id'))
                    ->update(['is_selected' => true, 'updated_at' => now()]);

                DB::table('islamic_mourabaha_requests')
                    ->where('id', $requestId)
                    ->update([
                        'request_status' => 'purchase_approved',
                        'updated_at' => now(),
                    ]);

                $approvalId = DB::table('islamic_mourabaha_purchase_approvals')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'mourabaha_request_id' => $requestId,
                    'supplier_quote_id' => $this->rowInt($quote, 'id'),
                    'decision' => is_string($validated['decision'] ?? null) ? $validated['decision'] : 'approved',
                    'decided_at' => now(),
                    'decided_by_user_id' => $actor->id,
                    'decision_context' => isset($validated['decision_context']) ? json_encode($validated['decision_context'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $approval = DB::table('islamic_mourabaha_purchase_approvals')->where('id', $approvalId)->first();
                if (! is_object($approval)) {
                    throw new InvalidArgumentException('Purchase approval could not be reloaded.');
                }

                return $approval;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_mourabaha_purchase_approval' => [$exception->getMessage()]]);
        }

        return $this->respondCreated($this->mourabahaPurchaseApprovalPayload($row), 'Mourabaha purchase approval recorded');
    }

    public function storePurchaseEvidence(Request $request, string $financingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'mourabaha_request_public_id' => ['sometimes', 'nullable', 'string', 'exists:islamic_mourabaha_requests,public_id'],
            'evidence_type' => ['sometimes', 'string', 'max:64'],
            'document_public_id' => ['sometimes', 'nullable', 'string', 'exists:documents,public_id'],
            'institution_control_status' => ['sometimes', Rule::in(['pending', 'controlled_by_institution', 'owned_by_institution'])],
            'evidence_context' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        try {
            $row = DB::transaction(function () use ($financingPublicId, $validated, $request): object {
                $financing = DB::table('islamic_financings')
                    ->where('public_id', $financingPublicId)
                    ->lockForUpdate()
                    ->first(['id', 'status']);
                if (! is_object($financing) || ! is_numeric($financing->id)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                if ($this->rowString($financing, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Purchase evidence can only be attached to draft financings.');
                }

                $requestId = null;
                $requestPublicId = is_string($validated['mourabaha_request_public_id'] ?? null) ? $validated['mourabaha_request_public_id'] : null;
                if ($requestPublicId !== null && $requestPublicId !== '') {
                    $requestRow = DB::table('islamic_mourabaha_requests')->where('public_id', $requestPublicId)->lockForUpdate()->first(['id']);
                    if (! is_object($requestRow) || ! is_numeric($requestRow->id)) {
                        throw new InvalidArgumentException('Mourabaha request is invalid.');
                    }
                    $requestId = (int) $requestRow->id;
                } else {
                    $requestId = DB::table('islamic_mourabaha_requests')->where('islamic_financing_id', $this->rowInt($financing, 'id'))->orderByDesc('id')->value('id');
                    $requestId = is_numeric($requestId) ? (int) $requestId : null;
                }
                if ($requestId !== null) {
                    $hasApprovedPurchase = DB::table('islamic_mourabaha_purchase_approvals')
                        ->where('mourabaha_request_id', $requestId)
                        ->where('decision', 'approved')
                        ->exists();
                    if (! $hasApprovedPurchase) {
                        throw new InvalidArgumentException('Purchase approval is required before purchase evidence can be attached.');
                    }
                } else {
                    throw new InvalidArgumentException('Purchase approval is required before purchase evidence can be attached.');
                }

                $id = DB::table('islamic_mourabaha_purchase_evidences')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financing_id' => $this->rowInt($financing, 'id'),
                    'mourabaha_request_id' => $requestId,
                    'evidence_type' => is_string($validated['evidence_type'] ?? null) && $validated['evidence_type'] !== '' ? $validated['evidence_type'] : 'supplier_invoice',
                    'document_public_id' => $this->nullableString($validated['document_public_id'] ?? null),
                    'institution_control_status' => is_string($validated['institution_control_status'] ?? null) ? $validated['institution_control_status'] : 'pending',
                    'evidence_context' => isset($validated['evidence_context']) ? json_encode($validated['evidence_context'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $evidence = DB::table('islamic_mourabaha_purchase_evidences')->where('id', $id)->first();
                if (! is_object($evidence)) {
                    throw new InvalidArgumentException('Purchase evidence could not be reloaded.');
                }

                $institutionControlStatus = is_string($validated['institution_control_status'] ?? null) ? $validated['institution_control_status'] : 'pending';
                $advancedAssets = [];
                if (in_array($institutionControlStatus, ['controlled_by_institution', 'owned_by_institution'], true)) {
                    $financingContractTypeValue = DB::table('islamic_financings')->where('id', $this->rowInt($financing, 'id'))->value('contract_type');
                    $financingContractType = is_string($financingContractTypeValue) ? $financingContractTypeValue : '';
                    $canonicalFamily = IslamicProductFamilyRegistry::familyForContractType($financingContractType) ?? $financingContractType;
                    $advancedAssets = $this->advanceMourabahaAssetsToPurchased(
                        $this->rowInt($financing, 'id'),
                        $institutionControlStatus,
                        $this->rowString($evidence, 'public_id'),
                        $request->user() instanceof User ? $request->user() : null,
                        $canonicalFamily,
                    );
                }

                return (object) ['evidence' => $evidence, 'advanced_assets' => $advancedAssets];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_mourabaha_purchase_evidence' => [$exception->getMessage()]]);
        }

        $actor = $request->user();
        if ($actor instanceof User) {
            foreach (($row->advanced_assets ?? []) as $advanced) {
                $this->securityAudit->record('islamic.asset.transitioned', actor: $actor, properties: [
                    'asset_public_id' => $advanced['asset_public_id'],
                    'from_status' => $advanced['from_status'],
                    'to_status' => $advanced['to_status'],
                    'transition_public_id' => $advanced['transition_public_id'],
                    'trigger' => 'mourabaha_purchase_evidence',
                ], request: $request);
                $this->securityAudit->record('islamic.asset.ownership_transferred_to_institution', actor: $actor, properties: [
                    'asset_public_id' => $advanced['asset_public_id'],
                    'financing_public_id' => $financingPublicId,
                    'ownership_status' => 'owned_by_institution',
                ], request: $request);
            }
        }

        return $this->respondCreated($this->mourabahaPurchaseEvidencePayload($row->evidence), 'Mourabaha purchase evidence attached');
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function assertEvidenceDocumentsExist(array $evidence): void
    {
        foreach (IslamicFinancedAssetStateMachine::documentBackedEvidenceKeys() as $key) {
            if (! array_key_exists($key, $evidence)) {
                continue;
            }
            $value = $evidence[$key];
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException(sprintf('Asset transition evidence "%s" must be a non-empty document public_id.', $key));
            }
            $exists = DB::table('documents')->where('public_id', $value)->exists();
            if (! $exists) {
                throw new InvalidArgumentException(sprintf('Asset transition evidence "%s" references unknown document "%s".', $key, $value));
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function advanceMourabahaAssetsToPurchased(int $financingId, string $institutionControlStatus, string $evidencePublicId, ?User $actor, string $productFamily = 'mourabaha'): array
    {
        $now = now();
        $assets = DB::table('islamic_financed_assets')
            ->where('islamic_financing_id', $financingId)
            ->whereIn('lifecycle_status', [
                IslamicFinancedAssetStateMachine::STATUS_REQUESTED,
                IslamicFinancedAssetStateMachine::STATUS_QUOTED,
            ])
            ->lockForUpdate()
            ->get(['id', 'public_id', 'lifecycle_status', 'supplier_name', 'supplier_reference']);

        $results = [];
        foreach ($assets as $asset) {
            $fromStatus = is_string($asset->lifecycle_status ?? null) ? $asset->lifecycle_status : '';
            $toStatus = IslamicFinancedAssetStateMachine::STATUS_PURCHASED;
            IslamicFinancedAssetStateMachine::assertTransitionAllowed($fromStatus, $toStatus);

            $screeningResultPublicId = null;
            $complianceCasePublicId = null;
            $assetPublicId = $this->rowString($asset, 'public_id');
            $supplierFlags = [];
            if (is_string($asset->supplier_name ?? null) && $asset->supplier_name !== '') {
                $supplierFlags[] = strtolower(trim($asset->supplier_name));
            }
            if (is_string($asset->supplier_reference ?? null) && $asset->supplier_reference !== '') {
                $supplierFlags[] = strtolower(trim($asset->supplier_reference));
            }
            $screeningFacts = [
                'scope_type' => 'product_family',
                'scope_value' => $productFamily,
                'asset_public_id' => $assetPublicId,
                'supplier_name' => is_string($asset->supplier_name ?? null) ? $asset->supplier_name : null,
                'supplier_reference' => is_string($asset->supplier_reference ?? null) ? $asset->supplier_reference : null,
                'supplier_flags' => $supplierFlags,
                'target_status' => $toStatus,
            ];
            $screeningOutcome = $this->screening->evaluate(
                subjectType: 'islamic_financed_asset',
                subjectPublicId: $assetPublicId,
                contextType: 'asset_acceptance',
                facts: $screeningFacts,
                actor: $actor,
                strictPolicy: false,
                overrideExceptionSubjectPublicId: null,
            );
            $screeningStatus = is_string($screeningOutcome['result'] ?? null) ? $screeningOutcome['result'] : 'not_applicable';
            $screeningResultPublicId = is_string($screeningOutcome['public_id'] ?? null) && $screeningOutcome['public_id'] !== '' ? $screeningOutcome['public_id'] : null;
            $complianceCasePublicId = is_string($screeningOutcome['review_case_public_id'] ?? null) && $screeningOutcome['review_case_public_id'] !== '' ? $screeningOutcome['review_case_public_id'] : null;

            if ($screeningStatus === 'fail') {
                throw new InvalidArgumentException(sprintf('Asset %s acceptance blocked by screening result while attaching Mourabaha purchase evidence.', $assetPublicId));
            }
            if ($screeningStatus === 'manual_review') {
                throw new InvalidArgumentException(sprintf('Asset %s acceptance requires manual compliance review before Mourabaha purchase evidence can advance it.', $assetPublicId));
            }

            $transitionPublicId = (string) Str::ulid();
            DB::table('islamic_financed_asset_transitions')->insert([
                'public_id' => $transitionPublicId,
                'islamic_financed_asset_id' => (int) $asset->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'reason_code' => 'mourabaha_purchase_evidence',
                'reason_note' => 'Auto-advanced by Mourabaha purchase evidence attachment.',
                'product_family' => $productFamily,
                'screening_result_public_id' => $screeningResultPublicId,
                'compliance_case_public_id' => $complianceCasePublicId,
                'evidence_refs' => json_encode([
                    'purchase_evidence' => $evidencePublicId,
                    'institution_control_status' => $institutionControlStatus,
                ], JSON_THROW_ON_ERROR),
                'context_snapshot' => json_encode([
                    'islamic_financing_id' => $financingId,
                ], JSON_THROW_ON_ERROR),
                'actor_user_id' => $actor?->id,
                'transitioned_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $assetUpdate = [
                'lifecycle_status' => $toStatus,
                'ownership_status' => 'owned_by_institution',
                'updated_at' => $now,
            ];
            if ($screeningResultPublicId !== null) {
                $assetUpdate['screening_result_public_id'] = $screeningResultPublicId;
            }
            DB::table('islamic_financed_assets')->where('id', $asset->id)->update($assetUpdate);

            $results[] = [
                'asset_public_id' => $assetPublicId,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'transition_public_id' => $transitionPublicId,
                'screening_result_public_id' => $screeningResultPublicId,
            ];
        }

        return $results;
    }

    public function storeCostEvidence(Request $request, string $financingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'cost_type' => ['required', 'string', 'max:64'],
            'amount_minor' => ['required', 'integer', 'min:0'],
            'document_public_id' => ['sometimes', 'nullable', 'string', 'exists:documents,public_id'],
            'evidence_context' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        try {
            $row = DB::transaction(function () use ($financingPublicId, $validated): object {
                $financing = DB::table('islamic_financings')
                    ->where('public_id', $financingPublicId)
                    ->lockForUpdate()
                    ->first(['id', 'status']);
                if (! is_object($financing) || ! is_numeric($financing->id)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                if ($this->rowString($financing, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Cost evidence can only be attached to draft financings.');
                }

                $id = DB::table('islamic_mourabaha_cost_evidences')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financing_id' => $this->rowInt($financing, 'id'),
                    'cost_type' => (string) $validated['cost_type'],
                    'amount_minor' => (int) $validated['amount_minor'],
                    'document_public_id' => $this->nullableString($validated['document_public_id'] ?? null),
                    'evidence_context' => isset($validated['evidence_context']) ? json_encode($validated['evidence_context'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $evidence = DB::table('islamic_mourabaha_cost_evidences')->where('id', $id)->first();
                if (! is_object($evidence)) {
                    throw new InvalidArgumentException('Cost evidence could not be reloaded.');
                }

                return $evidence;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_mourabaha_cost_evidence' => [$exception->getMessage()]]);
        }

        return $this->respondCreated($this->mourabahaCostEvidencePayload($row), 'Mourabaha cost evidence attached');
    }

    public function showOriginationSnapshot(Request $request, string $financingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->first(['id']);
        if (! is_object($financing) || ! is_numeric($financing->id)) {
            return $this->respondNotFound('Islamic financing not found.');
        }

        $snapshot = DB::table('islamic_mourabaha_contract_snapshots')
            ->where('islamic_financing_id', (int) $financing->id)
            ->orderByDesc('id')
            ->first();
        if (! is_object($snapshot)) {
            return $this->respondNotFound('Origination snapshot not found.');
        }

        return $this->respondSuccess($this->mourabahaSnapshotPayload($snapshot), 'Mourabaha origination snapshot retrieved');
    }

    public function storeCollection(Request $request, string $financingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(['XAF'])],
            'operation_code' => ['sometimes', 'string', 'max:128'],
            'event_reference' => ['sometimes', 'nullable', 'string', 'max:128'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $result = DB::transaction(function () use ($financingPublicId, $validated, $actor, $request): array {
                $financing = DB::table('islamic_financings')
                    ->where('public_id', $financingPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($financing)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                if ($this->rowString($financing, 'status') === 'approved') {
                    // approved financing is required for collection posting
                } else {
                    throw new InvalidArgumentException('Collections can only be posted for approved financings.');
                }

                $financingId = $this->rowInt($financing, 'id');
                $amount = (int) $validated['amount_minor'];
                $currency = is_string($validated['currency'] ?? null) && $validated['currency'] !== '' ? $validated['currency'] : $this->rowString($financing, 'currency');
                $operationCode = is_string($validated['operation_code'] ?? null) && $validated['operation_code'] !== '' ? $validated['operation_code'] : 'murabaha_collection';

                $scheduleTotal = (int) DB::table('islamic_financing_installments')
                    ->where('islamic_financing_id', $financingId)
                    ->sum('amount_minor');
                $salePrice = $this->rowInt($financing, 'sale_price_minor');
                if ($scheduleTotal !== $salePrice) {
                    throw new InvalidArgumentException('Installment schedule must reconcile exactly to approved sale price.');
                }

                $outstandingBefore = $this->outstandingReceivableMinor($financingId, $salePrice);
                if ($outstandingBefore <= 0) {
                    throw new InvalidArgumentException('No outstanding receivable remains for this financing.');
                }
                if ($amount > $outstandingBefore) {
                    throw new InvalidArgumentException('Collection amount cannot exceed outstanding receivable.');
                }

                $allocations = $this->allocateAgainstInstallments($financingId, $amount, incrementPaid: true);
                if ($allocations === []) {
                    throw new InvalidArgumentException('No eligible installment balance found for collection allocation.');
                }

                $this->interestGuard->assertIslamicMappingAllowed($operationCode);
                $agencyId = $this->rowInt($financing, 'agency_id');
                $debitMapping = $this->mappingValidation->resolvePostingMapping($operationCode, $agencyId, $currency, [
                    'side' => 'debit',
                    'lock_for_update' => true,
                    'actor' => $actor,
                    'request' => $request,
                ]);
                $creditMapping = $this->mappingValidation->resolvePostingMapping($operationCode, $agencyId, $currency, [
                    'side' => 'credit',
                    'lock_for_update' => true,
                    'actor' => $actor,
                    'request' => $request,
                ]);
                $debitLedger = $debitMapping['debit_ledger_account_id'];
                $creditLedger = $creditMapping['credit_ledger_account_id'];
                if (! is_int($debitLedger) || ! is_int($creditLedger)) {
                    throw new InvalidArgumentException('Approved Islamic mapping with both debit and credit ledgers is required for collection operation.');
                }

                $accountingDay = $this->accountingDayGuard->assertCanRegister($actor, 'islamic.financing', $agencyId);
                $businessDate = $accountingDay->business_date->toDateString();

                $journal = JournalEntry::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => 'MUR-COL-'.Str::upper(Str::random(10)),
                    'business_date' => $businessDate,
                    'accounting_day_id' => $accountingDay->id,
                    'posted_at' => null,
                    'agency_id' => $agencyId,
                    'source_module' => 'islamic_finance',
                    'source_type' => 'murabaha_collection',
                    'source_public_id' => $financingPublicId,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'description' => 'Murabaha collection '.$this->nullableString($validated['event_reference'] ?? null),
                    'created_by_user_id' => $actor->id,
                    'idempotency_key' => null,
                ]);
                $journal->lines()->createMany([
                    [
                        'public_id' => (string) Str::ulid(),
                        'agency_id' => $agencyId,
                        'ledger_account_id' => $debitLedger,
                        'debit_minor' => $amount,
                        'credit_minor' => 0,
                        'currency' => $currency,
                        'line_memo' => 'Murabaha collection debit',
                    ],
                    [
                        'public_id' => (string) Str::ulid(),
                        'agency_id' => $agencyId,
                        'ledger_account_id' => $creditLedger,
                        'debit_minor' => 0,
                        'credit_minor' => $amount,
                        'currency' => $currency,
                        'line_memo' => 'Murabaha collection credit',
                    ],
                ]);
                $this->postSystemJournal($journal, $actor);

                $outstandingAfter = $this->outstandingReceivableMinor($financingId, $salePrice);
                $eventId = DB::table('islamic_mourabaha_receivable_events')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financing_id' => $financingId,
                    'policy_id' => null,
                    'source_event_id' => null,
                    'journal_entry_id' => $journal->id,
                    'event_type' => 'collection',
                    'operation_code' => $operationCode,
                    'currency' => $currency,
                    'amount_minor' => $amount,
                    'outstanding_before_minor' => $outstandingBefore,
                    'outstanding_after_minor' => $outstandingAfter,
                    'status' => 'posted',
                    'event_snapshot' => json_encode([
                        'event_reference' => $this->nullableString($validated['event_reference'] ?? null),
                        'allocation_count' => count($allocations),
                    ], JSON_THROW_ON_ERROR),
                    'created_by_user_id' => $actor->id,
                    'posted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                foreach ($allocations as $allocation) {
                    DB::table('islamic_mourabaha_receivable_allocations')->insert([
                        'public_id' => (string) Str::ulid(),
                        'receivable_event_id' => $eventId,
                        'islamic_financing_installment_id' => $allocation['installment_id'],
                        'installment_number' => $allocation['installment_number'],
                        'allocated_minor' => $allocation['allocated_minor'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $event = DB::table('islamic_mourabaha_receivable_events')->where('id', $eventId)->first();
                if (! is_object($event)) {
                    throw new InvalidArgumentException('Collection event could not be reloaded.');
                }

                return ['event' => $event, 'journal' => $journal, 'allocations' => $allocations];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_mourabaha_collection' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.mourabaha.collection.posted', actor: $actor, properties: [
            'financing_public_id' => $financingPublicId,
            'event_public_id' => $this->rowString($result['event'], 'public_id'),
            'journal_entry_public_id' => $result['journal']->public_id,
            'allocations' => $result['allocations'],
        ], request: $request);

        return $this->respondCreated($this->mourabahaReceivableEventPayload($result['event']), 'Mourabaha collection posted');
    }

    public function storeReversal(Request $request, string $financingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'source_event_public_id' => ['required', 'string', 'exists:islamic_mourabaha_receivable_events,public_id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $result = DB::transaction(function () use ($financingPublicId, $validated, $actor, $request): array {
                $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->lockForUpdate()->first(['id', 'agency_id', 'sale_price_minor']);
                if (! is_object($financing) || ! is_numeric($financing->id)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                $event = DB::table('islamic_mourabaha_receivable_events')
                    ->where('public_id', (string) $validated['source_event_public_id'])
                    ->where('islamic_financing_id', (int) $financing->id)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($event)) {
                    throw new InvalidArgumentException('Source receivable event is invalid.');
                }
                if ($this->rowString($event, 'status') !== 'posted') {
                    throw new InvalidArgumentException('Only posted receivable events can be reversed.');
                }
                $sourceType = $this->rowString($event, 'event_type');
                if ($sourceType === 'reversal') {
                    throw new InvalidArgumentException('Reversal events cannot be reversed again.');
                }
                $existingReversal = DB::table('islamic_mourabaha_receivable_events')
                    ->where('source_event_id', $this->rowInt($event, 'id'))
                    ->where('event_type', 'reversal')
                    ->exists();
                if ($existingReversal) {
                    throw new InvalidArgumentException('This receivable event has already been reversed.');
                }

                $sourceOperationCode = $this->rowString($event, 'operation_code');
                $reversalPolicy = $this->resolveOperationCodeReversalPolicy($sourceOperationCode);
                $reversalMode = $reversalPolicy['reversal_mode'];
                $reversalOperationCode = $reversalPolicy['reversal_operation_code'] ?? null;
                if ($reversalMode === 'forbidden') {
                    throw new InvalidArgumentException('Operation code '.$sourceOperationCode.' is configured as non-reversible.');
                }
                if ($reversalMode === 'requires_reason' && $this->nullableString($validated['reason'] ?? null) === null) {
                    throw new InvalidArgumentException('Reversal reason is required by operation-code policy.');
                }
                if ($reversalMode === 'auto_reverse' && (! is_string($reversalOperationCode) || $reversalOperationCode === '')) {
                    throw new InvalidArgumentException('Operation code '.$sourceOperationCode.' requires configured reversal_operation_code.');
                }
                $resolvedReversalOperationCode = is_string($reversalOperationCode) && $reversalOperationCode !== ''
                    ? $reversalOperationCode
                    : $sourceOperationCode;
                $this->interestGuard->assertIslamicMappingAllowed($resolvedReversalOperationCode);
                if (is_numeric($financing->agency_id)) {
                    $this->mappingValidation->assertPostingAllowed(
                        operationCode: $resolvedReversalOperationCode,
                        agencyId: (int) $financing->agency_id,
                        currency: $this->rowString($event, 'currency'),
                        context: ['lock_for_update' => true, 'actor' => $actor, 'request' => $request]
                    );
                }
                $this->securityAudit->record('islamic.operation_code.reversal_validated', actor: $actor, properties: [
                    'source_operation_code' => $sourceOperationCode,
                    'reversal_mode' => $reversalMode,
                    'resolved_reversal_operation_code' => $resolvedReversalOperationCode,
                ], request: $request);

                $journal = JournalEntry::query()->find($this->rowNullableInt($event, 'journal_entry_id'));
                if (! $journal instanceof JournalEntry || $journal->status !== JournalEntry::STATUS_POSTED) {
                    throw new InvalidArgumentException('Only posted receivable journals can be reversed.');
                }
                $reversalJournal = $this->createJournalEntryReversal->execute($actor, $journal, true);

                $salePrice = is_numeric($financing->sale_price_minor ?? null) ? (int) $financing->sale_price_minor : 0;
                $outstandingBefore = $this->outstandingReceivableMinor((int) $financing->id, $salePrice);

                $allocations = DB::table('islamic_mourabaha_receivable_allocations')
                    ->where('receivable_event_id', $this->rowInt($event, 'id'))
                    ->orderBy('id')
                    ->get(['islamic_financing_installment_id', 'installment_number', 'allocated_minor']);
                foreach ($allocations as $allocation) {
                    $installment = DB::table('islamic_financing_installments')
                        ->where('id', (int) $allocation->islamic_financing_installment_id)
                        ->lockForUpdate()
                        ->first();
                    if (! is_object($installment)) {
                        continue;
                    }
                    $newPaid = max(0, $this->rowInt($installment, 'paid_amount_minor') - (int) $allocation->allocated_minor);
                    $status = $newPaid >= $this->rowInt($installment, 'amount_minor') ? 'paid' : 'pending';
                    DB::table('islamic_financing_installments')->where('id', $this->rowInt($installment, 'id'))->update([
                        'paid_amount_minor' => $newPaid,
                        'status' => $status,
                        'updated_at' => now(),
                    ]);
                }

                DB::table('islamic_mourabaha_receivable_events')->where('id', $this->rowInt($event, 'id'))->update([
                    'status' => 'reversed',
                    'updated_at' => now(),
                ]);

                $outstandingAfter = $this->outstandingReceivableMinor((int) $financing->id, $salePrice);
                $reversalEventId = DB::table('islamic_mourabaha_receivable_events')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financing_id' => (int) $financing->id,
                    'policy_id' => null,
                    'source_event_id' => $this->rowInt($event, 'id'),
                    'journal_entry_id' => $reversalJournal->id,
                    'event_type' => 'reversal',
                    'operation_code' => $resolvedReversalOperationCode,
                    'currency' => $this->rowString($event, 'currency'),
                    'amount_minor' => $this->rowInt($event, 'amount_minor'),
                    'outstanding_before_minor' => $outstandingBefore,
                    'outstanding_after_minor' => $outstandingAfter,
                    'status' => 'posted',
                    'event_snapshot' => json_encode([
                        'reason' => $this->nullableString($validated['reason'] ?? null),
                        'source_event_public_id' => $this->rowString($event, 'public_id'),
                    ], JSON_THROW_ON_ERROR),
                    'created_by_user_id' => $actor->id,
                    'posted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $reversalEvent = DB::table('islamic_mourabaha_receivable_events')->where('id', $reversalEventId)->first();
                if (! is_object($reversalEvent)) {
                    throw new InvalidArgumentException('Reversal event could not be reloaded.');
                }

                return ['event' => $reversalEvent, 'journal' => $reversalJournal];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_mourabaha_reversal' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.mourabaha.reversal.posted', actor: $actor, properties: [
            'financing_public_id' => $financingPublicId,
            'event_public_id' => $this->rowString($result['event'], 'public_id'),
            'journal_entry_public_id' => $result['journal']->public_id,
        ], request: $request);

        return $this->respondCreated($this->mourabahaReceivableEventPayload($result['event']), 'Mourabaha reversal posted');
    }

    public function storeRebate(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->storePolicyAdjustment($request, $financingPublicId, eventType: 'rebate', defaultOperationCode: 'murabaha_rebate');
    }

    public function storeCancellation(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->storePolicyAdjustment($request, $financingPublicId, eventType: 'cancellation', defaultOperationCode: 'murabaha_cancellation');
    }

    public function storeDefaultTreatment(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->storePolicyAdjustment($request, $financingPublicId, eventType: 'default_treatment', defaultOperationCode: 'murabaha_default_treatment');
    }

    public function storeCorrection(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->storePolicyAdjustment($request, $financingPublicId, eventType: 'correction', defaultOperationCode: 'murabaha_correction');
    }

    public function showReceivableLedger(Request $request, string $financingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->first(['id', 'sale_price_minor']);
        if (! is_object($financing) || ! is_numeric($financing->id)) {
            return $this->respondNotFound('Islamic financing not found.');
        }
        $items = $this->receivableLedgerItems((int) $financing->id);
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = mb_strtolower(trim($search));
            $items = array_values(array_filter($items, static function (array $item) use ($term): bool {
                $haystack = mb_strtolower(json_encode($item, JSON_THROW_ON_ERROR));

                return str_contains($haystack, $term);
            }));
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);
        $total = count($items);
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);
        $outstanding = $this->outstandingReceivableMinor((int) $financing->id, (int) $financing->sale_price_minor);

        return $this->respondSuccess([
            'financing_public_id' => $financingPublicId,
            'sale_price_minor' => (int) $financing->sale_price_minor,
            'outstanding_minor' => $outstanding,
            'ledger_items' => $slice,
        ], 'Mourabaha receivable ledger retrieved', meta: [
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil(max(1, $total) / $perPage),
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function receivableLedgerItems(int $financingId): array
    {
        $items = [];

        foreach (DB::table('islamic_mourabaha_receivable_events')
            ->where('islamic_financing_id', $financingId)
            ->orderBy('id')
            ->get() as $row) {
            $items[] = ['type' => 'event'] + $this->mourabahaReceivableEventPayload($row);
        }

        foreach (DB::table('islamic_financing_installments')
            ->where('islamic_financing_id', $financingId)
            ->orderBy('installment_number')
            ->get(['public_id', 'installment_number', 'due_on', 'amount_minor', 'paid_amount_minor', 'status']) as $row) {
            $items[] = [
                'type' => 'installment',
                'public_id' => $this->rowString($row, 'public_id'),
                'installment_number' => $this->rowInt($row, 'installment_number'),
                'due_on' => $this->rowNullableString($row, 'due_on'),
                'amount_minor' => $this->rowInt($row, 'amount_minor'),
                'paid_amount_minor' => $this->rowInt($row, 'paid_amount_minor'),
                'status' => $this->rowString($row, 'status'),
            ];
        }

        return $items;
    }

    public function approveFinancing(Request $request, string $financingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $this->runContractApprovalScreeningGate($financingPublicId, $actor);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_financing' => [$exception->getMessage()]]);
        }

        try {
            $row = DB::transaction(function () use ($financingPublicId, $actor, $request): object {
                $financing = DB::table('islamic_financings')
                    ->where('public_id', $financingPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($financing)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                if ($this->rowString($financing, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft financings can be approved.');
                }

                $financingId = $this->rowInt($financing, 'id');

                $contractType = $this->rowString($financing, 'contract_type');
                $canonicalFamily = IslamicProductFamilyRegistry::familyForContractType($contractType) ?? $contractType;

                if (IslamicFinancedAssetStateMachine::requiresAssetActivationGate($canonicalFamily)) {
                    $eligibleAssetStatuses = IslamicFinancedAssetStateMachine::activationGateStatusesFor($canonicalFamily);
                    $lockedAssetRows = DB::table('islamic_financed_assets')
                        ->where('islamic_financing_id', $financingId)
                        ->lockForUpdate()
                        ->get(['id', 'lifecycle_status']);
                    if ($lockedAssetRows->isEmpty()) {
                        throw new InvalidArgumentException(sprintf('%s financing requires at least one financed asset.', ucfirst($canonicalFamily)));
                    }
                    $eligibleAssetCount = $lockedAssetRows->filter(
                        static fn (object $row): bool => is_string($row->lifecycle_status ?? null) && in_array($row->lifecycle_status, $eligibleAssetStatuses, true)
                    )->count();
                    if ($eligibleAssetCount === 0) {
                        throw new InvalidArgumentException(sprintf(
                            '%s financing approval requires purchase/control evidence: at least one asset must be in %s status (IF-040 activation gate).',
                            ucfirst($canonicalFamily),
                            implode(' or ', $eligibleAssetStatuses),
                        ));
                    }
                } elseif ($canonicalFamily === 'salam') {
                    $this->salamGoods->assertGoodsReadyForApproval($financingId);
                } elseif ($canonicalFamily === 'istisnaa') {
                    $this->istisnaaProjects->assertProjectsReadyForApproval($financingId, $actor);
                }

                $this->assertLatestContractApprovalScreeningPass($financingPublicId);
                if ($canonicalFamily === 'mourabaha') {
                    $installmentCount = DB::table('islamic_financing_installments')
                        ->where('islamic_financing_id', $financingId)
                        ->count();
                    if ($installmentCount === 0) {
                        throw new InvalidArgumentException('Murabaha financing requires an installment schedule.');
                    }

                    $this->assertMourabahaOriginationChainSatisfied($financingId);

                    $agencyId = $this->rowInt($financing, 'agency_id');
                    $salePrice = $this->rowInt($financing, 'sale_price_minor');
                    $costBasis = $this->rowInt($financing, 'purchase_cost_minor') + $this->rowInt($financing, 'allowed_costs_minor');
                    $markup = $this->rowInt($financing, 'markup_minor');
                    $currency = $this->rowString($financing, 'currency');

                    $this->interestGuard->assertIslamicMappingAllowed('murabaha_receivable');
                    $receivableMapping = $this->mappingValidation->resolvePostingMapping('murabaha_receivable', $agencyId, $currency, [
                        'side' => 'debit',
                        'lock_for_update' => true,
                        'actor' => $actor,
                        'request' => $request,
                    ]);
                    $receivableLedger = $receivableMapping['debit_ledger_account_id'];
                    if (! is_int($receivableLedger)) {
                        throw new InvalidArgumentException('Approved Islamic mapping is required for murabaha_receivable (debit).');
                    }
                    $this->interestGuard->assertIslamicMappingAllowed('murabaha_payable');
                    $payableMapping = $this->mappingValidation->resolvePostingMapping('murabaha_payable', $agencyId, $currency, [
                        'side' => 'credit',
                        'lock_for_update' => true,
                        'actor' => $actor,
                        'request' => $request,
                    ]);
                    $payableLedger = $payableMapping['credit_ledger_account_id'];
                    if (! is_int($payableLedger)) {
                        throw new InvalidArgumentException('Approved Islamic mapping is required for murabaha_payable (credit).');
                    }
                    $this->interestGuard->assertIslamicMappingAllowed('murabaha_profit');
                    $profitMapping = $this->mappingValidation->resolvePostingMapping('murabaha_profit', $agencyId, $currency, [
                        'side' => 'credit',
                        'lock_for_update' => true,
                        'actor' => $actor,
                        'request' => $request,
                    ]);
                    $profitLedger = $profitMapping['credit_ledger_account_id'];
                    if (! is_int($profitLedger)) {
                        throw new InvalidArgumentException('Approved Islamic mapping is required for murabaha_profit (credit).');
                    }

                    $this->storeMourabahaContractSnapshot($financing, $actor->id);

                    $accountingDay = $this->accountingDayGuard->assertCanRegister($actor, 'islamic.financing', $agencyId);
                    $businessDate = $accountingDay->business_date->toDateString();

                    $journalEntry = JournalEntry::query()->create([
                        'public_id' => (string) Str::ulid(),
                        'reference' => 'MURABAHA-'.Str::upper(Str::random(10)),
                        'business_date' => $businessDate,
                        'accounting_day_id' => $accountingDay->id,
                        'posted_at' => null,
                        'agency_id' => $agencyId,
                        'source_module' => 'islamic_finance',
                        'source_type' => 'murabaha_financing',
                        'source_public_id' => $financingPublicId,
                        'status' => JournalEntry::STATUS_DRAFT,
                        'description' => 'Murabaha financing '.$this->rowString($financing, 'contract_number'),
                        'created_by_user_id' => $actor->id,
                        'idempotency_key' => 'murabaha-financing:'.$financingPublicId,
                    ]);

                    $journalEntry->lines()->createMany([
                        [
                            'public_id' => (string) Str::ulid(),
                            'agency_id' => $agencyId,
                            'ledger_account_id' => $receivableLedger,
                            'debit_minor' => $salePrice,
                            'credit_minor' => 0,
                            'currency' => $currency,
                            'line_memo' => 'Murabaha receivable (sale price)',
                        ],
                        [
                            'public_id' => (string) Str::ulid(),
                            'agency_id' => $agencyId,
                            'ledger_account_id' => $payableLedger,
                            'debit_minor' => 0,
                            'credit_minor' => $costBasis,
                            'currency' => $currency,
                            'line_memo' => 'Murabaha cost and allowed costs payable',
                        ],
                        [
                            'public_id' => (string) Str::ulid(),
                            'agency_id' => $agencyId,
                            'ledger_account_id' => $profitLedger,
                            'debit_minor' => 0,
                            'credit_minor' => $markup,
                            'currency' => $currency,
                            'line_memo' => 'Murabaha deferred profit',
                        ],
                    ]);

                    $this->postSystemJournal($journalEntry, $actor);

                    DB::table('islamic_financings')->where('id', $financingId)->update([
                        'status' => 'approved',
                        'approved_by_user_id' => $actor->id,
                        'approved_at' => now(),
                        'journal_entry_id' => $journalEntry->id,
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('islamic_financings')->where('id', $financingId)->update([
                        'status' => 'approved',
                        'approved_by_user_id' => $actor->id,
                        'approved_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $updated = DB::table('islamic_financings')->where('id', $financingId)->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Financing could not be reloaded.');
                }

                return $updated;
            });
        } catch (InvalidArgumentException $exception) {
            if (str_contains($exception->getMessage(), 'mapping')) {
                $this->securityAudit->record('islamic.mapping.use_blocked', actor: $actor, properties: [
                    'financing_public_id' => $financingPublicId,
                    'reason' => $exception->getMessage(),
                ], request: $request);
            }

            return $this->respondUnprocessable(errors: ['islamic_financing' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.financing.approved', actor: $actor, properties: [
            'financing_public_id' => $this->rowString($row, 'public_id'),
            'journal_entry_public_id' => $this->journalEntryPublicId($this->rowNullableInt($row, 'journal_entry_id')),
        ], request: $request);

        $message = $this->rowNullableInt($row, 'journal_entry_id') === null
            ? 'Islamic financing approved'
            : 'Islamic financing approved and posted';

        return $this->respondSuccess($this->financingPayload($row), $message);
    }

    public function storeIjaraConditionReport(Request $request, string $financingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'asset_public_id' => ['required', 'string', 'exists:islamic_financed_assets,public_id'],
            'condition_snapshot' => ['required', 'array'],
            'evidence_document_public_id' => ['required', 'string', 'exists:documents,public_id'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($financingPublicId, $validated, $actor): object {
                $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->lockForUpdate()->first();
                if (! is_object($financing)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                $family = IslamicProductFamilyRegistry::familyForContractType($this->rowString($financing, 'contract_type')) ?? $this->rowString($financing, 'contract_type');
                if (! in_array($family, ['ijara', 'ijara_wa_iqtina'], true)) {
                    throw new InvalidArgumentException('Condition report endpoint is only available for Ijara financings.');
                }
                $asset = DB::table('islamic_financed_assets')->where('public_id', (string) $validated['asset_public_id'])->lockForUpdate()->first();
                if (! is_object($asset) || $this->rowInt($asset, 'islamic_financing_id') !== $this->rowInt($financing, 'id')) {
                    throw new InvalidArgumentException('Condition report asset must belong to the target financing.');
                }
                $id = DB::table('islamic_ijara_condition_reports')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financing_id' => $this->rowInt($financing, 'id'),
                    'islamic_financed_asset_id' => $this->rowInt($asset, 'id'),
                    'evidence_document_public_id' => (string) $validated['evidence_document_public_id'],
                    'condition_snapshot' => json_encode($validated['condition_snapshot'], JSON_THROW_ON_ERROR),
                    'reported_by_user_id' => $actor->id,
                    'reported_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_ijara_condition_reports')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Ijara condition report could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_ijara_condition_report' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.ijara.condition_report_recorded', actor: $actor, properties: [
            'financing_public_id' => $financingPublicId,
            'condition_report_public_id' => $this->rowString($row, 'public_id'),
        ], request: $request);

        return $this->respondCreated([
            'public_id' => $this->rowString($row, 'public_id'),
            'evidence_document_public_id' => $this->rowString($row, 'evidence_document_public_id'),
            'reported_at' => $this->rowNullableString($row, 'reported_at'),
        ], 'Ijara condition report recorded');
    }

    public function storeIjaraRentalSchedules(Request $request, string $financingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.due_on' => ['required', 'date'],
            'lines.*.rental_amount_minor' => ['required', 'integer', 'min:1'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $result = DB::transaction(function () use ($financingPublicId, $validated): array {
                $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->lockForUpdate()->first();
                if (! is_object($financing)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                $family = IslamicProductFamilyRegistry::familyForContractType($this->rowString($financing, 'contract_type')) ?? $this->rowString($financing, 'contract_type');
                if (! in_array($family, ['ijara', 'ijara_wa_iqtina'], true)) {
                    throw new InvalidArgumentException('Rental schedule endpoint is only available for Ijara financings.');
                }
                if ($this->rowString($financing, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Rental schedule can only be configured while financing is draft.');
                }

                DB::table('islamic_ijara_rental_schedule_lines')
                    ->where('islamic_financing_id', $this->rowInt($financing, 'id'))
                    ->delete();

                $rows = [];
                foreach ((array) $validated['lines'] as $idx => $line) {
                    $publicId = (string) Str::ulid();
                    DB::table('islamic_ijara_rental_schedule_lines')->insert([
                        'public_id' => $publicId,
                        'islamic_financing_id' => $this->rowInt($financing, 'id'),
                        'line_number' => $idx + 1,
                        'due_on' => (string) $line['due_on'],
                        'rental_amount_minor' => (int) $line['rental_amount_minor'],
                        'status' => 'planned',
                        'paid_amount_minor' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $rows[] = DB::table('islamic_ijara_rental_schedule_lines')->where('public_id', $publicId)->first();
                }

                return [
                    'financing' => $financing,
                    'lines' => array_values(array_filter($rows, static fn (mixed $row): bool => is_object($row))),
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_ijara_rental_schedule' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.ijara.rental_schedule_created', actor: $actor, properties: [
            'financing_public_id' => $financingPublicId,
            'lines' => count($result['lines']),
        ], request: $request);

        return $this->respondCreated([
            'financing_public_id' => $financingPublicId,
            'lines' => array_map(fn (object $line): array => [
                'public_id' => $this->rowString($line, 'public_id'),
                'line_number' => $this->rowInt($line, 'line_number'),
                'due_on' => $this->rowNullableString($line, 'due_on'),
                'rental_amount_minor' => $this->rowInt($line, 'rental_amount_minor'),
                'status' => $this->rowString($line, 'status'),
            ], $result['lines']),
        ], 'Ijara rental schedule created');
    }

    public function activateIjaraLease(Request $request, string $financingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($financingPublicId, $actor, $request): object {
                $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->lockForUpdate()->first();
                if (! is_object($financing)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                $family = IslamicProductFamilyRegistry::familyForContractType($this->rowString($financing, 'contract_type')) ?? $this->rowString($financing, 'contract_type');
                if (! in_array($family, ['ijara', 'ijara_wa_iqtina'], true)) {
                    throw new InvalidArgumentException('Lease activation is only available for Ijara financings.');
                }
                if (! in_array($this->rowString($financing, 'status'), ['approved'], true)) {
                    throw new InvalidArgumentException('Ijara lease can only be activated from approved status.');
                }

                $eligibleAsset = DB::table('islamic_financed_assets')
                    ->where('islamic_financing_id', $this->rowInt($financing, 'id'))
                    ->where(function ($q): void {
                        $q->whereIn('ownership_status', ['owned_by_institution', 'controlled_by_institution'])
                            ->orWhereIn('lifecycle_status', [IslamicFinancedAssetStateMachine::STATUS_CONTROLLED, IslamicFinancedAssetStateMachine::STATUS_LEASED]);
                    })
                    ->lockForUpdate()
                    ->first();
                if (! is_object($eligibleAsset)) {
                    $this->securityAudit->record('islamic.ijara.activation_blocked_no_owned_or_controlled_asset', actor: $actor, properties: [
                        'financing_public_id' => $financingPublicId,
                    ], request: $request);
                    throw new InvalidArgumentException('Ijara lease activation requires an owned/controlled asset.');
                }

                $condition = DB::table('islamic_ijara_condition_reports')
                    ->where('islamic_financing_id', $this->rowInt($financing, 'id'))
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();
                if (! is_object($condition)) {
                    throw new InvalidArgumentException('Ijara lease activation requires a condition report with evidence.');
                }

                $scheduleLines = DB::table('islamic_ijara_rental_schedule_lines')
                    ->where('islamic_financing_id', $this->rowInt($financing, 'id'))
                    ->orderBy('line_number')
                    ->lockForUpdate()
                    ->get();
                if ($scheduleLines->isEmpty()) {
                    throw new InvalidArgumentException('Ijara lease activation requires rental schedule lines.');
                }

                $totalRental = $scheduleLines->sum(fn (object $line): int => $this->rowInt($line, 'rental_amount_minor'));
                $agencyId = $this->rowInt($financing, 'agency_id');
                $currency = $this->rowString($financing, 'currency');

                $this->interestGuard->assertIslamicMappingAllowed('ijara_rental_receivable');
                $receivable = $this->mappingValidation->resolvePostingMapping('ijara_rental_receivable', $agencyId, $currency, [
                    'side' => 'debit',
                    'lock_for_update' => true,
                    'actor' => $actor,
                    'request' => $request,
                ]);
                $this->interestGuard->assertIslamicMappingAllowed('ijara_rental_income');
                $income = $this->mappingValidation->resolvePostingMapping('ijara_rental_income', $agencyId, $currency, [
                    'side' => 'credit',
                    'lock_for_update' => true,
                    'actor' => $actor,
                    'request' => $request,
                ]);

                DB::table('islamic_ijara_accounting_posts')->insert([
                    [
                        'public_id' => (string) Str::ulid(),
                        'islamic_financing_id' => $this->rowInt($financing, 'id'),
                        'event_type' => 'lease_activation',
                        'operation_code' => 'ijara_rental_receivable',
                        'amount_minor' => $totalRental,
                        'mapping_public_id' => $receivable['mapping_public_id'],
                        'post_payload' => json_encode(['side' => 'debit', 'line_count' => $scheduleLines->count()], JSON_THROW_ON_ERROR),
                        'actor_user_id' => $actor->id,
                        'posted_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'public_id' => (string) Str::ulid(),
                        'islamic_financing_id' => $this->rowInt($financing, 'id'),
                        'event_type' => 'lease_activation',
                        'operation_code' => 'ijara_rental_income',
                        'amount_minor' => $totalRental,
                        'mapping_public_id' => $income['mapping_public_id'],
                        'post_payload' => json_encode(['side' => 'credit', 'line_count' => $scheduleLines->count()], JSON_THROW_ON_ERROR),
                        'actor_user_id' => $actor->id,
                        'posted_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ]);

                DB::table('islamic_financings')
                    ->where('id', $this->rowInt($financing, 'id'))
                    ->update([
                        'status' => 'active',
                        'updated_at' => now(),
                    ]);
                DB::table('islamic_financed_assets')
                    ->where('id', $this->rowInt($eligibleAsset, 'id'))
                    ->update([
                        'ownership_status' => 'leased_to_customer',
                        'lifecycle_status' => IslamicFinancedAssetStateMachine::STATUS_LEASED,
                        'updated_at' => now(),
                    ]);
                $updated = DB::table('islamic_financings')->where('id', $this->rowInt($financing, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Ijara financing could not be reloaded.');
                }

                return $updated;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_ijara_activation' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.ijara.contract_activated', actor: $actor, properties: [
            'financing_public_id' => $financingPublicId,
        ], request: $request);

        return $this->respondSuccess($this->financingPayload($row), 'Ijara lease activated');
    }

    public function storeIjaraDamageEvent(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->storeIjaraLifecycleEvent(
            request: $request,
            financingPublicId: $financingPublicId,
            eventType: 'damage',
            eventName: 'islamic.ijara.damage_event_reported',
            validationRules: [
                'incident_description' => ['required', 'string', 'max:2000'],
                'evidence_document_public_id' => ['required', 'string', 'exists:documents,public_id'],
            ],
        );
    }

    public function storeIjaraSuspension(Request $request, string $financingPublicId): JsonResponse
    {
        $response = $this->storeIjaraLifecycleEvent(
            request: $request,
            financingPublicId: $financingPublicId,
            eventType: 'suspension',
            eventName: 'islamic.ijara.rental_suspended',
            validationRules: [
                'reason' => ['required', 'string', 'max:2000'],
                'effective_on' => ['required', 'date'],
                'evidence_document_public_id' => ['required', 'string', 'exists:documents,public_id'],
            ],
            onCommitted: function (object $financing): void {
                DB::table('islamic_financings')->where('id', $this->rowInt($financing, 'id'))->update([
                    'status' => 'suspended',
                    'updated_at' => now(),
                ]);
                DB::table('islamic_ijara_rental_schedule_lines')
                    ->where('islamic_financing_id', $this->rowInt($financing, 'id'))
                    ->where('status', 'planned')
                    ->update([
                        'status' => 'suspended',
                        'updated_at' => now(),
                    ]);
            },
        );

        return $response;
    }

    public function storeIjaraEarlyTermination(Request $request, string $financingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:2000'],
            'settlement_adjustment_minor' => ['required', 'integer', 'min:0'],
            'evidence_document_public_id' => ['required', 'string', 'exists:documents,public_id'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $result = DB::transaction(function () use ($financingPublicId, $validated, $actor, $request): array {
                $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->lockForUpdate()->first();
                if (! is_object($financing)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                $family = IslamicProductFamilyRegistry::familyForContractType($this->rowString($financing, 'contract_type')) ?? $this->rowString($financing, 'contract_type');
                if (! in_array($family, ['ijara', 'ijara_wa_iqtina'], true)) {
                    throw new InvalidArgumentException('Early termination endpoint is only available for Ijara financings.');
                }
                if (! in_array($this->rowString($financing, 'status'), ['active', 'suspended'], true)) {
                    throw new InvalidArgumentException('Early termination requires an active or suspended Ijara financing.');
                }
                $this->interestGuard->assertIslamicMappingAllowed('ijara_termination_adjustment');
                $mapping = $this->mappingValidation->resolvePostingMapping(
                    'ijara_termination_adjustment',
                    $this->rowInt($financing, 'agency_id'),
                    $this->rowString($financing, 'currency'),
                    ['side' => 'credit', 'lock_for_update' => true, 'actor' => $actor, 'request' => $request],
                );
                DB::table('islamic_ijara_accounting_posts')->insert([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financing_id' => $this->rowInt($financing, 'id'),
                    'event_type' => 'early_termination',
                    'operation_code' => 'ijara_termination_adjustment',
                    'amount_minor' => (int) $validated['settlement_adjustment_minor'],
                    'mapping_public_id' => $mapping['mapping_public_id'],
                    'post_payload' => json_encode(['reason' => $validated['reason']], JSON_THROW_ON_ERROR),
                    'actor_user_id' => $actor->id,
                    'posted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('islamic_ijara_events')->insert([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financing_id' => $this->rowInt($financing, 'id'),
                    'event_type' => 'early_termination',
                    'workflow_state' => 'approved',
                    'evidence_document_public_id' => (string) $validated['evidence_document_public_id'],
                    'event_payload' => json_encode([
                        'reason' => $validated['reason'],
                        'settlement_adjustment_minor' => (int) $validated['settlement_adjustment_minor'],
                    ], JSON_THROW_ON_ERROR),
                    'actor_user_id' => $actor->id,
                    'occurred_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('islamic_financings')->where('id', $this->rowInt($financing, 'id'))->update([
                    'status' => 'terminated',
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_financings')->where('id', $this->rowInt($financing, 'id'))->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Islamic financing could not be reloaded.');
                }

                return ['financing' => $row];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_ijara_termination' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.ijara.early_termination_processed', actor: $actor, properties: [
            'financing_public_id' => $financingPublicId,
        ], request: $request);

        return $this->respondSuccess($this->financingPayload($result['financing']), 'Ijara early termination processed');
    }

    public function requestIjaraTransfer(Request $request, string $financingPublicId, string $assetPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'residual_amount_minor' => ['required', 'integer', 'min:0'],
            'waiver_amount_minor' => ['sometimes', 'integer', 'min:0'],
            'transfer_document_public_id' => ['required', 'string', 'exists:documents,public_id'],
            'customer_acceptance' => ['required', 'array'],
            'customer_acceptance.accepted_at' => ['required', 'date'],
            'customer_acceptance.accepted_by' => ['required', 'string', 'max:255'],
            'customer_acceptance.channel' => ['required', 'string', 'max:64'],
            'customer_acceptance.signature_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'approved_exception' => ['sometimes', 'nullable', 'array'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $residual = (int) $validated['residual_amount_minor'];
        $waiver = (int) ($validated['waiver_amount_minor'] ?? 0);
        if ($waiver > $residual) {
            return $this->respondUnprocessable(errors: ['islamic_ijara_transfer' => [__('Waiver amount cannot exceed residual amount.')]]);
        }

        try {
            $row = DB::transaction(function () use ($financingPublicId, $assetPublicId, $validated, $actor, $residual, $waiver): object {
                $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->lockForUpdate()->first();
                if (! is_object($financing)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                $family = IslamicProductFamilyRegistry::familyForContractType($this->rowString($financing, 'contract_type')) ?? $this->rowString($financing, 'contract_type');
                if ($family !== 'ijara_wa_iqtina') {
                    throw new InvalidArgumentException('Transfer workflow requires transfer-capable Ijara wa Iqtina financing.');
                }
                $asset = DB::table('islamic_financed_assets')->where('public_id', $assetPublicId)->lockForUpdate()->first();
                if (! is_object($asset) || $this->rowInt($asset, 'islamic_financing_id') !== $this->rowInt($financing, 'id')) {
                    throw new InvalidArgumentException('Transfer asset must belong to the target financing.');
                }

                $outstandingRentals = DB::table('islamic_ijara_rental_schedule_lines')
                    ->where('islamic_financing_id', $this->rowInt($financing, 'id'))
                    ->whereNotIn('status', ['paid_in_full', 'waived'])
                    ->count();
                $approvedException = is_array($validated['approved_exception'] ?? null) ? $validated['approved_exception'] : null;
                $hasApprovedException = is_array($approvedException)
                    && (($approvedException['approved'] ?? false) === true)
                    && is_string($approvedException['reference'] ?? null)
                    && trim($approvedException['reference']) !== '';
                if ($outstandingRentals > 0 && ! $hasApprovedException) {
                    throw new InvalidArgumentException('Transfer requires completed rental obligations or an approved exception.');
                }

                if ($residual === 0 && ! $hasApprovedException) {
                    throw new InvalidArgumentException('Zero residual transfer requires approved exception evidence.');
                }

                $id = DB::table('islamic_ijara_transfer_events')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financing_id' => $this->rowInt($financing, 'id'),
                    'islamic_financed_asset_id' => $this->rowInt($asset, 'id'),
                    'status' => 'requested',
                    'residual_amount_minor' => $residual,
                    'waiver_amount_minor' => $waiver,
                    'net_settlement_amount_minor' => $residual - $waiver,
                    'transfer_document_public_id' => (string) $validated['transfer_document_public_id'],
                    'customer_acceptance' => json_encode($validated['customer_acceptance'], JSON_THROW_ON_ERROR),
                    'exception_payload' => $approvedException !== null ? json_encode($approvedException, JSON_THROW_ON_ERROR) : null,
                    'requested_by_user_id' => $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_ijara_transfer_events')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Transfer request could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_ijara_transfer' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.ijara.transfer_requested', actor: $actor, properties: [
            'financing_public_id' => $financingPublicId,
            'asset_public_id' => $assetPublicId,
            'transfer_event_public_id' => $this->rowString($row, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->ijaraTransferPayload($row), 'Ijara transfer request created');
    }

    public function approveIjaraTransfer(Request $request, string $transferEventPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($transferEventPublicId, $actor): object {
                $event = DB::table('islamic_ijara_transfer_events')->where('public_id', $transferEventPublicId)->lockForUpdate()->first();
                if (! is_object($event)) {
                    throw new InvalidArgumentException('Ijara transfer event is invalid.');
                }
                if ($this->rowString($event, 'status') !== 'requested') {
                    throw new InvalidArgumentException('Only requested transfer events can be approved.');
                }
                DB::table('islamic_ijara_transfer_events')->where('id', $this->rowInt($event, 'id'))->update([
                    'status' => 'approved',
                    'approved_at' => now(),
                    'approved_by_user_id' => $actor->id,
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_ijara_transfer_events')->where('id', $this->rowInt($event, 'id'))->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Ijara transfer event could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_ijara_transfer' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.ijara.transfer_approved', actor: $actor, properties: [
            'transfer_event_public_id' => $transferEventPublicId,
        ], request: $request);

        return $this->respondSuccess($this->ijaraTransferPayload($row), 'Ijara transfer approved');
    }

    public function postIjaraTransfer(Request $request, string $transferEventPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'idempotency_key' => ['required', 'string', 'max:128'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        try {
            $event = DB::transaction(function () use ($transferEventPublicId, $validated, $actor, $request): object {
                $transfer = DB::table('islamic_ijara_transfer_events')->where('public_id', $transferEventPublicId)->lockForUpdate()->first();
                if (! is_object($transfer)) {
                    throw new InvalidArgumentException('Ijara transfer event is invalid.');
                }
                if ($this->rowString($transfer, 'status') === 'posted') {
                    throw new InvalidArgumentException('Transfer event is already posted.');
                }
                if (! in_array($this->rowString($transfer, 'status'), ['approved'], true)) {
                    throw new InvalidArgumentException('Only approved transfer events can be posted.');
                }
                if (DB::table('islamic_ijara_transfer_events')->where('idempotency_key', (string) $validated['idempotency_key'])->exists()) {
                    throw new InvalidArgumentException('Transfer idempotency_key already posted.');
                }

                $financing = DB::table('islamic_financings')
                    ->where('id', $this->rowInt($transfer, 'islamic_financing_id'))
                    ->lockForUpdate()
                    ->first();
                $asset = DB::table('islamic_financed_assets')
                    ->where('id', $this->rowInt($transfer, 'islamic_financed_asset_id'))
                    ->lockForUpdate()
                    ->first();
                if (! is_object($financing) || ! is_object($asset)) {
                    throw new InvalidArgumentException('Transfer subject financing/asset is invalid.');
                }

                $residual = $this->rowInt($transfer, 'residual_amount_minor');
                $waiver = $this->rowInt($transfer, 'waiver_amount_minor');
                $netSettlement = $residual - $waiver;
                if ($netSettlement < 0) {
                    throw new InvalidArgumentException('Transfer residual and waiver produce an invalid negative net settlement.');
                }

                $exceptionPayload = $this->decodeJson(((array) $transfer)['exception_payload'] ?? null);
                $hasApprovedException = is_array($exceptionPayload)
                    && (($exceptionPayload['approved'] ?? false) === true)
                    && is_string($exceptionPayload['reference'] ?? null)
                    && trim($exceptionPayload['reference']) !== '';

                if ($residual === 0 && ! $hasApprovedException) {
                    throw new InvalidArgumentException('Zero residual transfer requires approved exception evidence.');
                }

                $operationCode = $residual > 0 ? 'ijara_residual_transfer' : 'ijara_zero_residual_transfer';
                $this->interestGuard->assertIslamicMappingAllowed($operationCode);
                $mapping = $this->mappingValidation->resolvePostingMapping(
                    $operationCode,
                    $this->rowInt($financing, 'agency_id'),
                    $this->rowString($financing, 'currency'),
                    ['side' => 'credit', 'lock_for_update' => true, 'actor' => $actor, 'request' => $request],
                );

                DB::table('islamic_ijara_accounting_posts')->insert([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financing_id' => $this->rowInt($financing, 'id'),
                    'event_type' => 'transfer_posting',
                    'operation_code' => $operationCode,
                    'amount_minor' => $netSettlement,
                    'mapping_public_id' => $mapping['mapping_public_id'],
                    'post_payload' => json_encode([
                        'residual_amount_minor' => $residual,
                        'waiver_amount_minor' => $waiver,
                        'net_settlement_amount_minor' => $netSettlement,
                    ], JSON_THROW_ON_ERROR),
                    'actor_user_id' => $actor->id,
                    'posted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $fromStatus = $this->rowString($asset, 'lifecycle_status');
                DB::table('islamic_financed_assets')->where('id', $this->rowInt($asset, 'id'))->update([
                    'lifecycle_status' => IslamicFinancedAssetStateMachine::STATUS_TRANSFERRED,
                    'ownership_status' => 'owned_by_customer',
                    'updated_at' => now(),
                ]);
                DB::table('islamic_financed_asset_transitions')->insert([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financed_asset_id' => $this->rowInt($asset, 'id'),
                    'from_status' => $fromStatus,
                    'to_status' => IslamicFinancedAssetStateMachine::STATUS_TRANSFERRED,
                    'reason_code' => 'ijara_transfer_posted',
                    'reason_note' => 'Ownership transferred through approved Ijara wa Iqtina transfer workflow.',
                    'product_family' => 'ijara_wa_iqtina',
                    'screening_result_public_id' => null,
                    'compliance_case_public_id' => null,
                    'evidence_refs' => json_encode([
                        'transfer_document_public_id' => $this->rowString($transfer, 'transfer_document_public_id'),
                    ], JSON_THROW_ON_ERROR),
                    'context_snapshot' => json_encode([
                        'transfer_event_public_id' => $this->rowString($transfer, 'public_id'),
                        'residual_amount_minor' => $residual,
                        'waiver_amount_minor' => $waiver,
                        'net_settlement_amount_minor' => $netSettlement,
                    ], JSON_THROW_ON_ERROR),
                    'actor_user_id' => $actor->id,
                    'transitioned_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('islamic_ijara_transfer_events')->where('id', $this->rowInt($transfer, 'id'))->update([
                    'status' => 'posted',
                    'idempotency_key' => (string) $validated['idempotency_key'],
                    'posted_mapping_public_id' => $mapping['mapping_public_id'],
                    'posted_at' => now(),
                    'completed_at' => now(),
                    'posted_by_user_id' => $actor->id,
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_ijara_transfer_events')->where('id', $this->rowInt($transfer, 'id'))->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Transfer event could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_ijara_transfer' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.ijara.transfer_posted', actor: $actor, properties: [
            'transfer_event_public_id' => $transferEventPublicId,
        ], request: $request);

        return $this->respondSuccess($this->ijaraTransferPayload($event), 'Ijara transfer posted');
    }

    public function showIjaraTransferEvent(Request $request, string $transferEventPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $row = DB::table('islamic_ijara_transfer_events')->where('public_id', $transferEventPublicId)->first();
        if (! is_object($row)) {
            return $this->respondNotFound('Ijara transfer event not found.');
        }

        return $this->respondSuccess($this->ijaraTransferPayload($row));
    }

    private function runContractApprovalScreeningGate(string $financingPublicId, User $actor): void
    {
        if (! DB::table('islamic_screening_policies')->exists()) {
            return;
        }
        $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->first();
        if (! is_object($financing)) {
            throw new InvalidArgumentException('Islamic financing is invalid.');
        }
        $product = DB::table('islamic_products')->where('id', $this->rowInt($financing, 'islamic_product_id'))->first();
        if (! is_object($product)) {
            throw new InvalidArgumentException('Islamic product is invalid.');
        }
        $rulesRaw = is_string($product->rules ?? null) ? $product->rules : null;
        $rules = is_string($rulesRaw) && $rulesRaw !== '' ? json_decode($rulesRaw, true) : [];
        $rules = is_array($rules) ? $rules : [];
        $contractType = $this->rowString($financing, 'contract_type');
        $canonicalFamily = IslamicProductFamilyRegistry::familyForContractType($contractType) ?? $contractType;
        $screeningFacts = [
            'scope_type' => 'product_family',
            'scope_value' => $canonicalFamily,
            'agency_scope_value' => (string) $this->rowInt($financing, 'agency_id'),
            'supplier_flags' => is_array($rules['supplier_flags'] ?? null) ? $rules['supplier_flags'] : [],
            'goods_codes' => is_array($rules['goods_codes'] ?? null) ? $rules['goods_codes'] : [],
            'sector_codes' => is_array($rules['sector_codes'] ?? null) ? $rules['sector_codes'] : [],
        ];

        $this->screening->evaluateForAction(
            subjectType: 'islamic_financing',
            subjectPublicId: $financingPublicId,
            contextType: 'contract_approval',
            facts: $screeningFacts,
            actor: $actor,
            strictPolicy: true,
        );
    }

    private function assertLatestContractApprovalScreeningPass(string $financingPublicId): void
    {
        if (! DB::table('islamic_screening_policies')->exists()) {
            return;
        }
        $latest = DB::table('islamic_screening_results')
            ->where('subject_type', 'islamic_financing')
            ->where('subject_public_id', $financingPublicId)
            ->where('context_type', 'contract_approval')
            ->orderByDesc('id')
            ->first(['result']);
        if (! is_object($latest) || ! is_string($latest->result) || $latest->result !== 'pass') {
            throw new InvalidArgumentException('Contract approval requires a pass screening result.');
        }
    }

    private function storePolicyAdjustment(Request $request, string $financingPublicId, string $eventType, string $defaultOperationCode): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'policy_public_id' => ['required', 'string', 'exists:islamic_treatment_policies,public_id'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(['XAF'])],
            'operation_code' => ['sometimes', 'string', 'max:128'],
            'event_reference' => ['sometimes', 'nullable', 'string', 'max:128'],
            'source_event_public_id' => ['sometimes', 'nullable', 'string', 'exists:islamic_mourabaha_receivable_events,public_id'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $event = DB::transaction(function () use ($validated, $financingPublicId, $eventType, $defaultOperationCode, $actor, $request): object {
                $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->lockForUpdate()->first();
                if (! is_object($financing)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                if ($this->rowString($financing, 'status') === 'approved') {
                    // approved financing is required for adjustment posting
                } else {
                    throw new InvalidArgumentException('Adjustments can only be posted for approved financings.');
                }

                $policy = DB::table('islamic_treatment_policies')
                    ->where('public_id', (string) $validated['policy_public_id'])
                    ->lockForUpdate()
                    ->first();
                if (! is_object($policy)) {
                    throw new InvalidArgumentException('Treatment policy is invalid.');
                }
                if ($this->rowString($policy, 'status') === 'approved') {
                    // approved treatment policy is required for governed adjustment posting
                } else {
                    throw new InvalidArgumentException('Adjustment requires an approved treatment policy route.');
                }
                if ($eventType === 'correction' && (! is_string($validated['source_event_public_id'] ?? null) || $validated['source_event_public_id'] === '')) {
                    throw new InvalidArgumentException('Correction requires source event reference.');
                }

                $financingId = $this->rowInt($financing, 'id');
                $salePrice = $this->rowInt($financing, 'sale_price_minor');
                $amount = (int) $validated['amount_minor'];
                $outstandingBefore = $this->outstandingReceivableMinor($financingId, $salePrice);
                if ($amount > $outstandingBefore) {
                    throw new InvalidArgumentException('Adjustment amount cannot exceed outstanding receivable.');
                }

                $currency = is_string($validated['currency'] ?? null) && $validated['currency'] !== '' ? $validated['currency'] : $this->rowString($financing, 'currency');
                $operationCode = is_string($validated['operation_code'] ?? null) && $validated['operation_code'] !== '' ? $validated['operation_code'] : $defaultOperationCode;
                $this->interestGuard->assertIslamicMappingAllowed($operationCode);
                $agencyId = $this->rowInt($financing, 'agency_id');
                $debitMapping = $this->mappingValidation->resolvePostingMapping($operationCode, $agencyId, $currency, [
                    'side' => 'debit',
                    'lock_for_update' => true,
                    'actor' => $actor,
                    'request' => $request,
                ]);
                $creditMapping = $this->mappingValidation->resolvePostingMapping($operationCode, $agencyId, $currency, [
                    'side' => 'credit',
                    'lock_for_update' => true,
                    'actor' => $actor,
                    'request' => $request,
                ]);
                $debitLedger = $debitMapping['debit_ledger_account_id'];
                $creditLedger = $creditMapping['credit_ledger_account_id'];
                if (! is_int($debitLedger) || ! is_int($creditLedger)) {
                    throw new InvalidArgumentException('Approved Islamic mapping with both debit and credit ledgers is required for adjustment operation.');
                }

                $allocations = $this->allocateAgainstInstallments($financingId, $amount, incrementPaid: true);
                if ($allocations === []) {
                    throw new InvalidArgumentException('No eligible installment balance found for adjustment allocation.');
                }

                $accountingDay = $this->accountingDayGuard->assertCanRegister($actor, 'islamic.financing', $agencyId);
                $businessDate = $accountingDay->business_date->toDateString();

                $journal = JournalEntry::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => 'MUR-ADJ-'.Str::upper(Str::random(10)),
                    'business_date' => $businessDate,
                    'accounting_day_id' => $accountingDay->id,
                    'posted_at' => null,
                    'agency_id' => $agencyId,
                    'source_module' => 'islamic_finance',
                    'source_type' => 'murabaha_'.$eventType,
                    'source_public_id' => $financingPublicId,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'description' => 'Murabaha '.$eventType.' '.$this->nullableString($validated['event_reference'] ?? null),
                    'created_by_user_id' => $actor->id,
                    'idempotency_key' => null,
                ]);
                $journal->lines()->createMany([
                    [
                        'public_id' => (string) Str::ulid(),
                        'agency_id' => $agencyId,
                        'ledger_account_id' => $debitLedger,
                        'debit_minor' => $amount,
                        'credit_minor' => 0,
                        'currency' => $currency,
                        'line_memo' => 'Murabaha '.$eventType.' debit',
                    ],
                    [
                        'public_id' => (string) Str::ulid(),
                        'agency_id' => $agencyId,
                        'ledger_account_id' => $creditLedger,
                        'debit_minor' => 0,
                        'credit_minor' => $amount,
                        'currency' => $currency,
                        'line_memo' => 'Murabaha '.$eventType.' credit',
                    ],
                ]);
                $this->postSystemJournal($journal, $actor);

                $sourceEventId = null;
                if (is_string($validated['source_event_public_id'] ?? null) && $validated['source_event_public_id'] !== '') {
                    $source = DB::table('islamic_mourabaha_receivable_events')
                        ->where('public_id', $validated['source_event_public_id'])
                        ->where('islamic_financing_id', $financingId)
                        ->first(['id', 'status', 'event_type']);
                    if (! is_object($source) || ! is_numeric($source->id)) {
                        throw new InvalidArgumentException('Source event for correction is invalid.');
                    }
                    if ($this->rowString($source, 'status') !== 'posted') {
                        throw new InvalidArgumentException('Correction source event must be posted.');
                    }
                    if ($eventType === 'correction' && $this->rowString($source, 'event_type') === 'reversal') {
                        throw new InvalidArgumentException('Correction source event cannot be a reversal.');
                    }
                    $sourceEventId = (int) $source->id;
                }

                $outstandingAfter = $this->outstandingReceivableMinor($financingId, $salePrice);
                $eventId = DB::table('islamic_mourabaha_receivable_events')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financing_id' => $financingId,
                    'policy_id' => $this->rowInt($policy, 'id'),
                    'source_event_id' => $sourceEventId,
                    'journal_entry_id' => $journal->id,
                    'event_type' => $eventType,
                    'operation_code' => $operationCode,
                    'currency' => $currency,
                    'amount_minor' => $amount,
                    'outstanding_before_minor' => $outstandingBefore,
                    'outstanding_after_minor' => $outstandingAfter,
                    'status' => 'posted',
                    'event_snapshot' => json_encode([
                        'policy_public_id' => $this->rowString($policy, 'public_id'),
                        'event_reference' => $this->nullableString($validated['event_reference'] ?? null),
                        'source_event_public_id' => is_string($validated['source_event_public_id'] ?? null) ? $validated['source_event_public_id'] : null,
                    ], JSON_THROW_ON_ERROR),
                    'created_by_user_id' => $actor->id,
                    'posted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                foreach ($allocations as $allocation) {
                    DB::table('islamic_mourabaha_receivable_allocations')->insert([
                        'public_id' => (string) Str::ulid(),
                        'receivable_event_id' => $eventId,
                        'islamic_financing_installment_id' => $allocation['installment_id'],
                        'installment_number' => $allocation['installment_number'],
                        'allocated_minor' => $allocation['allocated_minor'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $event = DB::table('islamic_mourabaha_receivable_events')->where('id', $eventId)->first();
                if (! is_object($event)) {
                    throw new InvalidArgumentException('Receivable adjustment event could not be reloaded.');
                }

                return $event;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_mourabaha_'.$eventType => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.mourabaha.'.$eventType.'.applied', actor: $actor, properties: [
            'financing_public_id' => $financingPublicId,
            'event_public_id' => $this->rowString($event, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->mourabahaReceivableEventPayload($event), 'Mourabaha '.$eventType.' posted');
    }

    private function outstandingReceivableMinor(int $financingId, int $salePriceMinor): int
    {
        $paid = (int) DB::table('islamic_financing_installments')
            ->where('islamic_financing_id', $financingId)
            ->sum('paid_amount_minor');

        return max(0, $salePriceMinor - $paid);
    }

    /**
     * @return list<array{installment_id:int,installment_number:int,allocated_minor:int}>
     */
    private function allocateAgainstInstallments(int $financingId, int $amountMinor, bool $incrementPaid): array
    {
        $remaining = $amountMinor;
        $allocations = [];

        $installments = DB::table('islamic_financing_installments')
            ->where('islamic_financing_id', $financingId)
            ->orderBy('due_on')
            ->orderBy('installment_number')
            ->lockForUpdate()
            ->get();

        foreach ($installments as $installment) {
            if ($remaining <= 0) {
                break;
            }

            $amount = $this->rowInt($installment, 'amount_minor');
            $paid = $this->rowInt($installment, 'paid_amount_minor');
            $available = $amount - $paid;
            if ($available <= 0) {
                continue;
            }

            $allocated = min($available, $remaining);
            $remaining -= $allocated;

            $newPaid = $incrementPaid ? $paid + $allocated : max(0, $paid - $allocated);
            $status = $newPaid >= $amount ? 'paid' : 'pending';
            DB::table('islamic_financing_installments')->where('id', $this->rowInt($installment, 'id'))->update([
                'paid_amount_minor' => $newPaid,
                'status' => $status,
                'updated_at' => now(),
            ]);

            $allocations[] = [
                'installment_id' => $this->rowInt($installment, 'id'),
                'installment_number' => $this->rowInt($installment, 'installment_number'),
                'allocated_minor' => $allocated,
            ];
        }

        if ($remaining > 0) {
            return [];
        }

        return $allocations;
    }

    private function assertMourabahaOriginationChainSatisfied(int $financingId): void
    {
        $hasApprovedPurchase = DB::table('islamic_mourabaha_purchase_approvals as pa')
            ->join('islamic_mourabaha_requests as r', 'r.id', '=', 'pa.mourabaha_request_id')
            ->where('r.islamic_financing_id', $financingId)
            ->where('pa.decision', 'approved')
            ->exists();
        if (! $hasApprovedPurchase) {
            throw new InvalidArgumentException('Murabaha financing requires approved purchase approval before sale contract approval.');
        }

        $hasControlEvidence = DB::table('islamic_mourabaha_purchase_evidences')
            ->where('islamic_financing_id', $financingId)
            ->whereIn('institution_control_status', ['controlled_by_institution', 'owned_by_institution'])
            ->exists();
        if (! $hasControlEvidence) {
            throw new InvalidArgumentException('Murabaha financing requires purchase/control evidence before sale contract approval.');
        }

        $hasCostEvidence = DB::table('islamic_mourabaha_cost_evidences')
            ->where('islamic_financing_id', $financingId)
            ->exists();
        if (! $hasCostEvidence) {
            throw new InvalidArgumentException('Murabaha financing requires cost evidence before sale contract approval.');
        }
    }

    private function storeMourabahaContractSnapshot(object $financing, int $actorUserId): void
    {
        $financingId = $this->rowInt($financing, 'id');
        $installments = DB::table('islamic_financing_installments')
            ->where('islamic_financing_id', $financingId)
            ->orderBy('installment_number')
            ->get(['installment_number', 'due_on', 'amount_minor', 'currency']);
        $purchaseApproval = DB::table('islamic_mourabaha_purchase_approvals as pa')
            ->join('islamic_mourabaha_requests as r', 'r.id', '=', 'pa.mourabaha_request_id')
            ->leftJoin('islamic_mourabaha_supplier_quotes as q', 'q.id', '=', 'pa.supplier_quote_id')
            ->where('r.islamic_financing_id', $financingId)
            ->where('pa.decision', 'approved')
            ->orderByDesc('pa.id')
            ->first([
                'pa.public_id as approval_public_id',
                'r.public_id as request_public_id',
                'q.public_id as quote_public_id',
            ]);
        $purchaseEvidenceRefs = DB::table('islamic_mourabaha_purchase_evidences')
            ->where('islamic_financing_id', $financingId)
            ->orderBy('id')
            ->get(['public_id', 'evidence_type', 'document_public_id', 'institution_control_status']);
        $costEvidenceRefs = DB::table('islamic_mourabaha_cost_evidences')
            ->where('islamic_financing_id', $financingId)
            ->orderBy('id')
            ->get(['public_id', 'cost_type', 'amount_minor', 'document_public_id']);

        $payload = [
            'contract_type' => $this->rowString($financing, 'contract_type'),
            'currency' => $this->rowString($financing, 'currency'),
            'purchase_cost_minor' => $this->rowInt($financing, 'purchase_cost_minor'),
            'allowed_costs_minor' => $this->rowInt($financing, 'allowed_costs_minor'),
            'markup_minor' => $this->rowInt($financing, 'markup_minor'),
            'sale_price_minor' => $this->rowInt($financing, 'sale_price_minor'),
            'schedule_terms' => $installments->map(fn (object $inst): array => [
                'installment_number' => $this->rowInt($inst, 'installment_number'),
                'due_on' => $this->rowNullableString($inst, 'due_on'),
                'amount_minor' => $this->rowInt($inst, 'amount_minor'),
                'currency' => $this->rowString($inst, 'currency'),
            ])->all(),
            'purchase_chain' => [
                'request_public_id' => is_object($purchaseApproval) ? (is_string($purchaseApproval->request_public_id ?? null) ? $purchaseApproval->request_public_id : null) : null,
                'quote_public_id' => is_object($purchaseApproval) ? (is_string($purchaseApproval->quote_public_id ?? null) ? $purchaseApproval->quote_public_id : null) : null,
                'purchase_approval_public_id' => is_object($purchaseApproval) ? (is_string($purchaseApproval->approval_public_id ?? null) ? $purchaseApproval->approval_public_id : null) : null,
                'purchase_evidence_refs' => $purchaseEvidenceRefs->map(fn (object $ref): array => [
                    'public_id' => $this->rowString($ref, 'public_id'),
                    'evidence_type' => $this->rowString($ref, 'evidence_type'),
                    'document_public_id' => $this->rowNullableString($ref, 'document_public_id'),
                    'institution_control_status' => $this->rowString($ref, 'institution_control_status'),
                ])->all(),
                'cost_evidence_refs' => $costEvidenceRefs->map(fn (object $ref): array => [
                    'public_id' => $this->rowString($ref, 'public_id'),
                    'cost_type' => $this->rowString($ref, 'cost_type'),
                    'amount_minor' => $this->rowInt($ref, 'amount_minor'),
                    'document_public_id' => $this->rowNullableString($ref, 'document_public_id'),
                ])->all(),
            ],
        ];
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        DB::table('islamic_mourabaha_contract_snapshots')->insert([
            'public_id' => (string) Str::ulid(),
            'islamic_financing_id' => $financingId,
            'snapshot_payload' => $payloadJson,
            'snapshot_hash' => hash('sha256', $payloadJson),
            'created_by_user_id' => $actorUserId,
            'snapshot_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function postSystemJournal(JournalEntry $journalEntry, User $actor): void
    {
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitted_by_user_id' => $actor->id,
        ])->save();
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_APPROVED,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $actor->id,
        ])->save();
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_POSTED,
            'posted_at' => now(),
            'posted_by_user_id' => $actor->id,
        ])->save();
    }

    private function journalEntryPublicId(?int $journalEntryId): ?string
    {
        if ($journalEntryId === null) {
            return null;
        }

        $row = DB::table('journal_entries')->where('id', $journalEntryId)->first(['public_id']);

        return is_object($row) && is_string($row->public_id) ? $row->public_id : null;
    }

    private function tablePublicIdById(string $table, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $row = DB::table($table)->where('id', $id)->first(['public_id']);

        return is_object($row) && is_string($row->public_id) ? $row->public_id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function financingPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'contract_number' => $this->rowString($row, 'contract_number'),
            'contract_type' => $this->rowString($row, 'contract_type'),
            'purchase_cost_minor' => $this->rowInt($row, 'purchase_cost_minor'),
            'allowed_costs_minor' => $this->rowInt($row, 'allowed_costs_minor'),
            'markup_minor' => $this->rowInt($row, 'markup_minor'),
            'sale_price_minor' => $this->rowInt($row, 'sale_price_minor'),
            'status' => $this->rowString($row, 'status'),
            'currency' => $this->rowString($row, 'currency'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assetPayload(object $row): array
    {
        $documentBundleRaw = ((array) $row)['document_bundle'] ?? null;
        $documentBundle = null;
        if (is_string($documentBundleRaw) && $documentBundleRaw !== '') {
            $decoded = json_decode($documentBundleRaw, true);
            $documentBundle = is_array($decoded) ? $decoded : null;
        }

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'asset_type' => $this->rowString($row, 'asset_type'),
            'asset_category' => $this->rowNullableString($row, 'asset_category'),
            'description' => $this->rowString($row, 'description'),
            'supplier_name' => $this->rowNullableString($row, 'supplier_name'),
            'supplier_reference' => $this->rowNullableString($row, 'supplier_reference'),
            'purchase_amount_minor' => $this->rowNullableInt($row, 'purchase_amount_minor'),
            'acquisition_cost_minor' => $this->rowNullableInt($row, 'acquisition_cost_minor'),
            'currency' => $this->rowString($row, 'currency'),
            'location' => $this->rowNullableString($row, 'location'),
            'condition_status' => $this->rowNullableString($row, 'condition_status'),
            'document_bundle' => $documentBundle,
            'customer_request_ref' => $this->rowNullableString($row, 'customer_request_ref'),
            'screening_result_public_id' => $this->rowNullableString($row, 'screening_result_public_id'),
            'ownership_status' => $this->rowString($row, 'ownership_status'),
            'lifecycle_status' => $this->rowString($row, 'lifecycle_status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assetTransitionPayload(object $row): array
    {
        $evidenceRaw = ((array) $row)['evidence_refs'] ?? null;
        $evidence = null;
        if (is_string($evidenceRaw) && $evidenceRaw !== '') {
            $decoded = json_decode($evidenceRaw, true);
            $evidence = is_array($decoded) ? $decoded : null;
        }

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'from_status' => $this->rowNullableString($row, 'from_status'),
            'to_status' => $this->rowString($row, 'to_status'),
            'reason_code' => $this->rowNullableString($row, 'reason_code'),
            'reason_note' => $this->rowNullableString($row, 'reason_note'),
            'product_family' => $this->rowNullableString($row, 'product_family'),
            'screening_result_public_id' => $this->rowNullableString($row, 'screening_result_public_id'),
            'compliance_case_public_id' => $this->rowNullableString($row, 'compliance_case_public_id'),
            'evidence_refs' => $evidence,
            'transitioned_at' => $this->rowNullableString($row, 'transitioned_at'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function financedAssetTimelineItems(int $assetId): array
    {
        return array_map(
            fn (object $row): array => ['type' => 'transition'] + $this->assetTransitionPayload($row),
            DB::table('islamic_financed_asset_transitions')
                ->where('islamic_financed_asset_id', $assetId)
                ->orderBy('id')
                ->get()
                ->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function installmentPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'installment_number' => $this->rowInt($row, 'installment_number'),
            'due_on' => $this->rowNullableString($row, 'due_on'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
            'status' => $this->rowString($row, 'status'),
        ];
    }

    /** @return array<string, mixed> */
    private function mourabahaRequestPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'request_status' => $this->rowString($row, 'request_status'),
            'asset_type' => $this->rowNullableString($row, 'asset_type'),
            'asset_description' => $this->rowNullableString($row, 'asset_description'),
            'requested_purchase_cost_minor' => $this->rowNullableInt($row, 'requested_purchase_cost_minor'),
            'supplier_name' => $this->rowNullableString($row, 'supplier_name'),
        ];
    }

    /** @return array<string, mixed> */
    private function mourabahaQuotePayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'supplier_name' => $this->rowString($row, 'supplier_name'),
            'quoted_purchase_cost_minor' => $this->rowInt($row, 'quoted_purchase_cost_minor'),
            'quoted_allowed_costs_minor' => $this->rowInt($row, 'quoted_allowed_costs_minor'),
            'currency' => $this->rowString($row, 'currency'),
            'valid_until' => $this->rowNullableString($row, 'valid_until'),
            'is_selected' => (bool) (((array) $row)['is_selected'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private function mourabahaPurchaseApprovalPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'decision' => $this->rowString($row, 'decision'),
            'decided_at' => $this->rowNullableString($row, 'decided_at'),
        ];
    }

    /** @return array<string, mixed> */
    private function mourabahaPurchaseEvidencePayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'evidence_type' => $this->rowString($row, 'evidence_type'),
            'document_public_id' => $this->rowNullableString($row, 'document_public_id'),
            'institution_control_status' => $this->rowString($row, 'institution_control_status'),
        ];
    }

    /** @return array<string, mixed> */
    private function mourabahaCostEvidencePayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'cost_type' => $this->rowString($row, 'cost_type'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
            'document_public_id' => $this->rowNullableString($row, 'document_public_id'),
        ];
    }

    /** @return array<string, mixed> */
    private function mourabahaSnapshotPayload(object $row): array
    {
        $payloadRaw = $this->rowNullableString($row, 'snapshot_payload');
        $decoded = is_string($payloadRaw) ? json_decode($payloadRaw, true) : null;

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'snapshot_hash' => $this->rowString($row, 'snapshot_hash'),
            'snapshot_at' => $this->rowNullableString($row, 'snapshot_at'),
            'snapshot_payload' => is_array($decoded) ? $decoded : null,
        ];
    }

    /** @return array<string, mixed> */
    private function mourabahaReceivableEventPayload(object $row): array
    {
        $eventId = $this->rowNullableInt($row, 'id');
        $policyPublicId = $this->tablePublicIdById('islamic_treatment_policies', $this->rowNullableInt($row, 'policy_id'));
        $sourceEventPublicId = $this->tablePublicIdById('islamic_mourabaha_receivable_events', $this->rowNullableInt($row, 'source_event_id'));
        $journalPublicId = $this->tablePublicIdById('journal_entries', $this->rowNullableInt($row, 'journal_entry_id'));
        $snapshotRaw = $this->rowNullableString($row, 'event_snapshot');
        $snapshot = is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : null;

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'event_type' => $this->rowString($row, 'event_type'),
            'operation_code' => $this->rowString($row, 'operation_code'),
            'currency' => $this->rowString($row, 'currency'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
            'outstanding_before_minor' => $this->rowInt($row, 'outstanding_before_minor'),
            'outstanding_after_minor' => $this->rowInt($row, 'outstanding_after_minor'),
            'status' => $this->rowString($row, 'status'),
            'posted_at' => $this->rowNullableString($row, 'posted_at'),
            'policy_public_id' => $policyPublicId,
            'source_event_public_id' => $sourceEventPublicId,
            'journal_entry_public_id' => $journalPublicId,
            'allocations' => $eventId === null ? [] : $this->receivableAllocationsPayload($eventId),
            'event_snapshot' => is_array($snapshot) ? $snapshot : null,
        ];
    }

    /** @return list<array{installment_number:int,allocated_minor:int}> */
    private function receivableAllocationsPayload(int $receivableEventId): array
    {
        $allocations = DB::table('islamic_mourabaha_receivable_allocations')
            ->where('receivable_event_id', $receivableEventId)
            ->orderBy('installment_number')
            ->orderBy('id')
            ->get(['installment_number', 'allocated_minor'])
            ->map(fn (object $row): array => [
                'installment_number' => $this->rowInt($row, 'installment_number'),
                'allocated_minor' => $this->rowInt($row, 'allocated_minor'),
            ])
            ->values()
            ->all();

        return array_values($allocations);
    }

    /**
     * @param  array<string, mixed>  $validationRules
     * @param  callable(object):void|null  $onCommitted
     */
    private function storeIjaraLifecycleEvent(
        Request $request,
        string $financingPublicId,
        string $eventType,
        string $eventName,
        array $validationRules,
        ?callable $onCommitted = null,
    ): JsonResponse {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), $validationRules)->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($financingPublicId, $eventType, $validated, $actor, $onCommitted): object {
                $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->lockForUpdate()->first();
                if (! is_object($financing)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                $family = IslamicProductFamilyRegistry::familyForContractType($this->rowString($financing, 'contract_type')) ?? $this->rowString($financing, 'contract_type');
                if (! in_array($family, ['ijara', 'ijara_wa_iqtina'], true)) {
                    throw new InvalidArgumentException('Ijara lifecycle event endpoint is only available for Ijara financings.');
                }
                if (! in_array($this->rowString($financing, 'status'), ['active', 'suspended'], true)) {
                    throw new InvalidArgumentException('Ijara lifecycle events require financing in active or suspended status.');
                }

                $evidenceDocumentPublicId = is_string($validated['evidence_document_public_id'] ?? null)
                    ? $validated['evidence_document_public_id']
                    : null;
                $eventPublicId = (string) Str::ulid();
                DB::table('islamic_ijara_events')->insert([
                    'public_id' => $eventPublicId,
                    'islamic_financing_id' => $this->rowInt($financing, 'id'),
                    'event_type' => $eventType,
                    'workflow_state' => 'under_review',
                    'evidence_document_public_id' => $evidenceDocumentPublicId,
                    'event_payload' => json_encode($validated, JSON_THROW_ON_ERROR),
                    'actor_user_id' => $actor->id,
                    'occurred_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($onCommitted !== null) {
                    $onCommitted($financing);
                }
                $row = DB::table('islamic_ijara_events')->where('public_id', $eventPublicId)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Ijara lifecycle event could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_ijara_event' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record($eventName, actor: $actor, properties: [
            'financing_public_id' => $financingPublicId,
            'event_public_id' => $this->rowString($row, 'public_id'),
        ], request: $request);

        return $this->respondCreated([
            'public_id' => $this->rowString($row, 'public_id'),
            'event_type' => $this->rowString($row, 'event_type'),
            'workflow_state' => $this->rowString($row, 'workflow_state'),
            'evidence_document_public_id' => $this->rowNullableString($row, 'evidence_document_public_id'),
            'occurred_at' => $this->rowNullableString($row, 'occurred_at'),
        ], 'Ijara lifecycle event created');
    }

    /**
     * @return array<string, mixed>
     */
    private function ijaraTransferPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'status' => $this->rowString($row, 'status'),
            'residual_amount_minor' => $this->rowInt($row, 'residual_amount_minor'),
            'waiver_amount_minor' => $this->rowInt($row, 'waiver_amount_minor'),
            'net_settlement_amount_minor' => $this->rowInt($row, 'net_settlement_amount_minor'),
            'transfer_document_public_id' => $this->rowString($row, 'transfer_document_public_id'),
            'customer_acceptance' => $this->decodeJson(((array) $row)['customer_acceptance'] ?? null),
            'exception_payload' => $this->decodeJson(((array) $row)['exception_payload'] ?? null),
            'idempotency_key' => $this->rowNullableString($row, 'idempotency_key'),
            'posted_mapping_public_id' => $this->rowNullableString($row, 'posted_mapping_public_id'),
            'approved_at' => $this->rowNullableString($row, 'approved_at'),
            'posted_at' => $this->rowNullableString($row, 'posted_at'),
            'completed_at' => $this->rowNullableString($row, 'completed_at'),
        ];
    }

    private function requirePlatformAdmin(Request $request): bool
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin');
    }

    private function idByPublicId(string $table, mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $row = DB::table($table)->where('public_id', $publicId)->first(['id']);

        return is_object($row) && is_numeric($row->id) ? (int) $row->id : null;
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function rowInt(object $row, string $key): int
    {
        $value = ((array) $row)[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function rowNullableInt(object $row, string $key): ?int
    {
        $value = ((array) $row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @return array<string, mixed>|array<int, mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array{
     *   template: object,
     *   fallback_used: bool,
     *   preferred_language_code: string|null,
     *   selected_language_code: string
     * }
     */
    private function resolveTemplateForOrigination(
        string $familyCode,
        ?string $explicitTemplatePublicId,
        ?string $preferredLanguageCode,
        bool $allowLanguageFallback,
    ): array {
        $query = DB::table('islamic_contract_templates')
            ->where('family_code', $familyCode)
            ->where('status', 'approved');

        $asOfDate = now()->toDateString();
        $query->where('effective_from', '<=', $asOfDate)
            ->where(function ($q) use ($asOfDate): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $asOfDate);
            });

        if (is_string($explicitTemplatePublicId) && $explicitTemplatePublicId !== '') {
            $template = $query->where('public_id', $explicitTemplatePublicId)->first();
            if (! is_object($template)) {
                throw new InvalidArgumentException('Explicit Islamic contract template is not approved/effective for this product family.');
            }

            return [
                'template' => $template,
                'fallback_used' => false,
                'preferred_language_code' => $preferredLanguageCode,
                'selected_language_code' => $this->rowString($template, 'language_code'),
            ];
        }

        $selectionLanguage = is_string($preferredLanguageCode) && $preferredLanguageCode !== ''
            ? $preferredLanguageCode
            : 'fr';

        $languageCandidates = (clone $query)
            ->where('language_code', $selectionLanguage)
            ->get();
        if ($languageCandidates->isNotEmpty()) {
            $template = $this->resolveLatestNonConflictingTemplate($languageCandidates, 'template_language_code='.$selectionLanguage);

            return [
                'template' => $template,
                'fallback_used' => false,
                'preferred_language_code' => $preferredLanguageCode,
                'selected_language_code' => $this->rowString($template, 'language_code'),
            ];
        }

        if (! $allowLanguageFallback) {
            throw new InvalidArgumentException('No approved and effective Islamic contract template is available for language '.$selectionLanguage.'. Enable allow_template_language_fallback or select an explicit template.');
        }

        $fallbackCandidates = $query->get();
        if ($fallbackCandidates->isEmpty()) {
            throw new InvalidArgumentException('No approved and effective Islamic contract template is available for this product family.');
        }
        $fallback = $this->resolveLatestNonConflictingTemplate($fallbackCandidates, 'fallback');

        return [
            'template' => $fallback,
            'fallback_used' => true,
            'preferred_language_code' => $preferredLanguageCode,
            'selected_language_code' => $this->rowString($fallback, 'language_code'),
        ];
    }

    /**
     * @param  Collection<int, \stdClass>  $candidates
     */
    private function resolveLatestNonConflictingTemplate(Collection $candidates, string $scope): object
    {
        $latestVersion = $candidates
            ->filter(function (\stdClass $row): bool {
                $payload = get_object_vars($row);

                return is_numeric($payload['version'] ?? null);
            })
            ->max(function (\stdClass $row): int {
                $payload = get_object_vars($row);
                $version = $payload['version'] ?? null;
                if (is_int($version)) {
                    return $version;
                }
                if (is_float($version)) {
                    return (int) $version;
                }
                if (is_string($version) && is_numeric($version)) {
                    return (int) $version;
                }

                return 0;
            });
        if (! is_int($latestVersion)) {
            throw new InvalidArgumentException('Template candidate set is invalid.');
        }

        $latestCandidates = $candidates
            ->filter(function (\stdClass $row) use ($latestVersion): bool {
                $payload = get_object_vars($row);
                $version = $payload['version'] ?? null;

                return is_numeric($version) && (int) $version === $latestVersion;
            })
            ->values();

        if ($latestCandidates->count() > 1) {
            throw new InvalidArgumentException('Multiple approved/effective Islamic contract templates are eligible for '.$scope.' at version '.$latestVersion.'. Select an explicit contract_template_public_id.');
        }

        $selected = $latestCandidates->first();
        if (! is_object($selected)) {
            throw new InvalidArgumentException('No approved and effective Islamic contract template is available for this product family.');
        }

        return $selected;
    }

    /**
     * @return array{reversal_mode: string, reversal_operation_code: ?string}
     */
    private function resolveOperationCodeReversalPolicy(string $operationCode): array
    {
        $row = DB::table('operation_codes')
            ->where('module', 'islamic_finance')
            ->where('code', $operationCode)
            ->first(['metadata']);
        if (! is_object($row)) {
            return ['reversal_mode' => 'manual_reverse', 'reversal_operation_code' => null];
        }

        $metadata = $this->decodeJson(((array) $row)['metadata'] ?? null);
        $profile = is_array($metadata['islamic_profile'] ?? null) ? $metadata['islamic_profile'] : $metadata;
        $mode = is_string($profile['reversal_mode'] ?? null) ? $profile['reversal_mode'] : 'manual_reverse';
        $reversalCode = is_string($profile['reversal_operation_code'] ?? null) ? $profile['reversal_operation_code'] : null;
        if (! in_array($mode, ['auto_reverse', 'manual_reverse', 'forbidden', 'requires_reason'], true)) {
            $mode = 'manual_reverse';
        }

        return [
            'reversal_mode' => $mode,
            'reversal_operation_code' => $reversalCode,
        ];
    }
}
