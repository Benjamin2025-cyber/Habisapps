<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Http\Controllers\BaseController;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

final class IslamicProductWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly IslamicProductReadinessService $readiness,
        private readonly IslamicShariaAuthorityService $shariaAuthority,
        private readonly IslamicApprovalWorkflowService $approvalWorkflow,
        private readonly IslamicComplianceCaseService $complianceCases,
        private readonly IslamicInterestGuardPolicy $interestGuard,
        private readonly IslamicProductFamilyRegistry $productFamilies,
    ) {}

    public function storeProduct(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'code' => ['required', 'string', 'max:64', 'unique:islamic_products,code'],
            'name' => ['required', 'string', 'max:255'],
            'contract_type' => ['required', Rule::in(IslamicProductFamilyRegistry::supportedContractTypes())],
            'default_margin_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'rules' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $rules = is_array($validated['rules'] ?? null) ? $validated['rules'] : [];
        try {
            $this->interestGuard->assertNoConventionalInterestBinding($rules);
            $statementLabels = is_array($rules['statement_labels'] ?? null) ? array_values(array_filter($rules['statement_labels'], 'is_string')) : [];
            if ($statementLabels !== []) {
                $this->interestGuard->assertStatementTerminologyAllowed($statementLabels);
            }
            $this->productFamilies->assertDraftRulesAllowed((string) $validated['contract_type'], $rules);
        } catch (InvalidArgumentException $exception) {
            $this->securityAudit->record('islamic.interest_guard.product_binding_rejected', actor: $actor, properties: [
                'code' => (string) $validated['code'],
                'reason' => $exception->getMessage(),
            ], request: $request);

            return $this->respondUnprocessable(errors: ['islamic_interest_guardrails' => [$exception->getMessage()]]);
        }

        $id = DB::transaction(function () use ($validated, $actor, $request): int {
            $agencyId = $this->idByPublicId('agencies', $validated['agency_public_id'] ?? null);

            $publicId = (string) Str::ulid();
            $insertedId = DB::table('islamic_products')->insertGetId([
                'public_id' => $publicId,
                'agency_id' => $agencyId,
                'code' => (string) $validated['code'],
                'name' => (string) $validated['name'],
                'contract_type' => (string) $validated['contract_type'],
                'default_margin_rate' => is_numeric($validated['default_margin_rate'] ?? null) ? (float) $validated['default_margin_rate'] : null,
                'status' => 'draft',
                'rules' => isset($validated['rules']) ? json_encode($validated['rules'], JSON_THROW_ON_ERROR) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->approvalWorkflow->ensureWorkflow(
                IslamicApprovalStateMachine::SUBJECT_PRODUCT,
                $publicId,
                $actor,
                $request,
            );

            return $insertedId;
        });

        $row = DB::table('islamic_products')->where('id', $id)->first();
        if (! is_object($row)) {
            return $this->respondUnprocessable(errors: ['islamic_product' => [__('Product could not be reloaded.')]]);
        }

        $this->securityAudit->record('islamic.product.created', actor: $actor, properties: [
            'product_public_id' => $this->rowString($row, 'public_id'),
            'code' => $this->rowString($row, 'code'),
            'contract_type' => $this->rowString($row, 'contract_type'),
        ], request: $request);

        return $this->respondCreated($this->productPayload($row), 'Islamic product created');
    }

    public function indexProductFamilies(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $items = $this->productFamilies->allMetadata();
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
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($items, $offset, $perPage);

        return $this->respondSuccess($slice, 'Islamic product families retrieved', meta: [
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil(max(1, $total) / $perPage),
            ],
        ]);
    }

    public function showProductFamily(Request $request, string $familyCode): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $metadata = $this->productFamilies->metadataFor($familyCode);
        if ($metadata === null) {
            return $this->respondNotFound('Islamic product family not found.');
        }

        return $this->respondSuccess($metadata, 'Islamic product family retrieved');
    }

    public function storeComplianceReview(Request $request, string $productPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'checklist' => ['sometimes', 'nullable', 'array'],
            'reason_code' => ['sometimes', 'string', 'max:64'],
            'risk_level' => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])],
            'checklist_version' => ['sometimes', 'string', 'max:64'],
            'assigned_reviewer_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'blocking_mode' => ['sometimes', Rule::in(['hard', 'soft'])],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($productPublicId, $validated, $actor): object {
                $product = DB::table('islamic_products')->where('public_id', $productPublicId)->lockForUpdate()->first();
                if (! is_object($product)) {
                    throw new InvalidArgumentException('Islamic product is invalid.');
                }
                $workflowState = $this->approvalWorkflow->workflowFor(
                    IslamicApprovalStateMachine::SUBJECT_PRODUCT,
                    $productPublicId,
                );
                if ($workflowState === null || ((array) $workflowState)['current_state'] !== IslamicApprovalStateMachine::STATE_DRAFT) {
                    throw new InvalidArgumentException('Only draft products can be submitted for Sharia compliance review.');
                }

                $id = DB::table('islamic_compliance_reviews')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_product_id' => $this->rowInt($product, 'id'),
                    'islamic_financing_id' => null,
                    'requested_by_user_id' => $actor->id,
                    'status' => 'pending',
                    'decision' => 'pending',
                    'comments' => $this->nullableString($validated['comments'] ?? null),
                    'checklist' => isset($validated['checklist']) ? json_encode($validated['checklist'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('islamic_compliance_reviews')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Compliance review could not be reloaded.');
                }

                $case = $this->complianceCases->openCase(
                    subjectType: IslamicComplianceCaseService::SUBJECT_PRODUCT,
                    subjectPublicId: $productPublicId,
                    reasonCode: is_string($validated['reason_code'] ?? null) ? $validated['reason_code'] : 'sharia_product_review',
                    riskLevel: is_string($validated['risk_level'] ?? null) ? $validated['risk_level'] : 'high',
                    checklistVersion: is_string($validated['checklist_version'] ?? null) ? $validated['checklist_version'] : 'v1',
                    actor: $actor,
                    assignedReviewerUserId: isset($validated['assigned_reviewer_user_id']) && is_numeric($validated['assigned_reviewer_user_id']) ? (int) $validated['assigned_reviewer_user_id'] : null,
                    dueAt: isset($validated['due_at']) && is_string($validated['due_at']) ? CarbonImmutable::parse($validated['due_at']) : null,
                    blockingMode: is_string($validated['blocking_mode'] ?? null) ? $validated['blocking_mode'] : 'hard',
                    metadata: array_merge(
                        is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [],
                        ['legacy_review_public_id' => $this->rowString($row, 'public_id')],
                    ),
                );
                $this->complianceCases->addBlocker(
                    casePublicId: $this->rowString($case, 'public_id'),
                    blockerType: IslamicComplianceCaseService::BLOCKER_PRODUCT_ACTIVATION,
                    targetSubjectType: IslamicComplianceCaseService::SUBJECT_PRODUCT,
                    targetSubjectPublicId: $productPublicId,
                    actor: $actor,
                );

                $this->approvalWorkflow->applyDecision(
                    IslamicApprovalStateMachine::SUBJECT_PRODUCT,
                    $productPublicId,
                    $actor,
                    IslamicApprovalStateMachine::DECISION_SUBMIT,
                    ['comments' => $this->nullableString($validated['comments'] ?? null)],
                );

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_compliance_review' => [$exception->getMessage()]]);
        }

        return $this->respondCreated($this->complianceReviewPayload($row), 'Sharia compliance review requested');
    }

    public function reviewCompliance(Request $request, string $reviewPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'decision' => ['required', Rule::in([
                'approve',
                'reject',
                'needs_information',
                'conditionally_approved',
                'suspend',
                'corrective_action_required',
                'corrective_action_closed',
            ])],
            'comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'conditions' => ['sometimes', 'nullable', 'array'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after:effective_from'],
            'evidence_document_public_id' => ['sometimes', 'nullable', 'string', 'exists:documents,public_id'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $readinessSnapshot = null;

        try {
            $row = DB::transaction(function () use ($reviewPublicId, $validated, $actor, &$readinessSnapshot): object {
                $review = DB::table('islamic_compliance_reviews')
                    ->where('public_id', $reviewPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($review)) {
                    throw new InvalidArgumentException('Compliance review is invalid.');
                }
                if ($this->rowString($review, 'status') !== 'pending') {
                    throw new InvalidArgumentException('Compliance review has already been decided.');
                }
                $requesterId = $this->rowNullableInt($review, 'requested_by_user_id');
                if ($requesterId !== null && $requesterId === $actor->id) {
                    throw new InvalidArgumentException('Requester cannot review their own compliance request.');
                }

                $newDecision = match ($validated['decision']) {
                    'approve' => 'approved',
                    'reject' => 'rejected',
                    'needs_information' => 'needs_information',
                    'conditionally_approved' => 'conditionally_approved',
                    'suspend' => 'suspended',
                    'corrective_action_required' => 'corrective_action_required',
                    'corrective_action_closed' => 'corrective_action_closed',
                    default => throw new InvalidArgumentException('Decision is invalid.'),
                };

                if ($newDecision === 'approved') {
                    $productId = $this->rowNullableInt($review, 'islamic_product_id');
                    if ($productId !== null) {
                        $product = DB::table('islamic_products')->where('id', $productId)->lockForUpdate()->first();
                        if (! is_object($product)) {
                            throw new InvalidArgumentException('Islamic product is invalid.');
                        }
                        $readiness = $this->readiness->evaluate($product, null, $actor);
                        $this->securityAudit->record('islamic.product.readiness.evaluated', actor: $actor, properties: [
                            'product_public_id' => $this->rowString($product, 'public_id'),
                            'family_code' => $readiness['family_code'],
                            'overall_status' => $readiness['overall_status'],
                            'failed_gates' => array_keys($readiness['failures_by_gate']),
                        ]);
                        $failures = $readiness['failures_by_gate'];

                        $family = $this->productFamilyForContractType($this->rowString($product, 'contract_type'));
                        $scope = $family !== null ? ['product_family' => $family] : [];
                        $authorityFailures = $this->shariaAuthority->activeMandateFailures(
                            $actor,
                            IslamicShariaAuthorityService::DECISION_TYPE_PRODUCT_COMPLIANCE_APPROVAL,
                            $scope,
                            null,
                            $requesterId,
                        );
                        if ($authorityFailures !== []) {
                            $failures['islamic_sharia_authority'] = $authorityFailures;
                        }

                        if ($failures !== []) {
                            throw new ReadinessGateFailure($failures);
                        }

                        $readinessSnapshot = $this->storeReadinessSnapshot(
                            product: $product,
                            actor: $actor,
                            reviewPublicId: $reviewPublicId,
                            readiness: $readiness,
                        );
                    }
                }

                $legacyDecision = in_array($newDecision, ['approved', 'conditionally_approved', 'corrective_action_closed'], true) ? 'approved' : ($newDecision === 'rejected' ? 'rejected' : 'pending');
                $legacyStatus = $legacyDecision;
                DB::table('islamic_compliance_reviews')->where('id', $this->rowInt($review, 'id'))->update([
                    'status' => $legacyStatus,
                    'decision' => $legacyDecision,
                    'reviewed_by_user_id' => $actor->id,
                    'reviewed_at' => now(),
                    'comments' => $this->nullableString($validated['comments'] ?? null),
                    'updated_at' => now(),
                ]);

                $productIdForMirror = $this->rowNullableInt($review, 'islamic_product_id');
                if ($productIdForMirror !== null) {
                    $productRow = DB::table('islamic_products')->where('id', $productIdForMirror)->first(['public_id']);
                    $productPublicId = is_object($productRow) && is_string($productRow->public_id) ? $productRow->public_id : null;
                    if ($productPublicId !== null) {
                        $case = DB::table('islamic_compliance_cases')
                            ->where('subject_type', IslamicComplianceCaseService::SUBJECT_PRODUCT)
                            ->where('subject_public_id', $productPublicId)
                            ->whereRaw("metadata->>'legacy_review_public_id' = ?", [$reviewPublicId])
                            ->whereIn('status', ['open', 'in_review', 'blocked', 'resolved'])
                            ->latest('id')
                            ->first();
                        if (is_object($case)) {
                            $this->complianceCases->recordDecision($this->rowString($case, 'public_id'), $newDecision, $actor, [
                                'decision_comments' => $this->nullableString($validated['comments'] ?? null),
                                'conditions' => is_array($validated['conditions'] ?? null) ? $validated['conditions'] : null,
                                'effective_from' => is_string($validated['effective_from'] ?? null) ? $validated['effective_from'] : null,
                                'effective_to' => is_string($validated['effective_to'] ?? null) ? $validated['effective_to'] : null,
                                'evidence_document_id' => $this->idByPublicId('documents', $validated['evidence_document_public_id'] ?? null),
                            ]);
                        }
                    }

                    if (in_array($newDecision, ['approved', 'conditionally_approved', 'corrective_action_closed'], true)) {
                        DB::table('islamic_products')->where('id', $productIdForMirror)->update([
                            'status' => 'approved',
                            'updated_at' => now(),
                        ]);
                        if ($productPublicId !== null) {
                            $this->approvalWorkflow->applyDecision(
                                IslamicApprovalStateMachine::SUBJECT_PRODUCT,
                                $productPublicId,
                                $actor,
                                IslamicApprovalStateMachine::DECISION_APPROVE,
                                [
                                    'comments' => $this->nullableString($validated['comments'] ?? null),
                                    'requester_user_id' => $requesterId,
                                    'skip_authority_check' => true,
                                ],
                            );
                        }
                    } elseif (in_array($newDecision, ['rejected', 'suspended', 'corrective_action_required'], true) && $productPublicId !== null) {
                        $this->approvalWorkflow->applyDecision(
                            IslamicApprovalStateMachine::SUBJECT_PRODUCT,
                            $productPublicId,
                            $actor,
                            $newDecision === 'suspended' ? IslamicApprovalStateMachine::DECISION_SUSPEND : IslamicApprovalStateMachine::DECISION_REJECT,
                            ['comments' => $this->nullableString($validated['comments'] ?? null)],
                        );
                    }
                }

                $updated = DB::table('islamic_compliance_reviews')->where('id', $this->rowInt($review, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Compliance review could not be reloaded.');
                }

                return $updated;
            });
        } catch (ReadinessGateFailure $failure) {
            $this->securityAudit->record('islamic.product.readiness_blocked', actor: $actor, properties: [
                'review_public_id' => $reviewPublicId,
                'failed_gates' => array_keys($failure->failures),
                'failures' => $failure->failures,
            ], request: $request);
            $this->securityAudit->record('islamic.product.readiness.blocked', actor: $actor, properties: [
                'review_public_id' => $reviewPublicId,
                'failed_gates' => array_keys($failure->failures),
                'failures' => $failure->failures,
            ], request: $request);

            if (isset($failure->failures['islamic_sharia_authority'])) {
                $this->securityAudit->record('islamic.sharia_authority.decision_blocked', actor: $actor, properties: [
                    'review_public_id' => $reviewPublicId,
                    'decision_type' => IslamicShariaAuthorityService::DECISION_TYPE_PRODUCT_COMPLIANCE_APPROVAL,
                    'reasons' => $failure->failures['islamic_sharia_authority'],
                ], request: $request);
            }
            if (isset($failure->failures['islamic_regulatory_signoff'])) {
                $this->securityAudit->record('islamic.regulatory_signoff.readiness_blocked', actor: $actor, properties: [
                    'review_public_id' => $reviewPublicId,
                    'decision_type' => IslamicShariaAuthorityService::DECISION_TYPE_PRODUCT_COMPLIANCE_APPROVAL,
                    'reasons' => $failure->failures['islamic_regulatory_signoff'],
                ], request: $request);
            }

            return $this->respondUnprocessable(errors: $failure->failures);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_compliance_review' => [$exception->getMessage()]]);
        }

        if (is_array($readinessSnapshot)) {
            $this->securityAudit->record('islamic.product.readiness.approved', actor: $actor, properties: [
                'review_public_id' => $reviewPublicId,
                'snapshot_public_id' => $readinessSnapshot['snapshot_public_id'],
                'snapshot_hash' => $readinessSnapshot['snapshot_hash'],
                'family_code' => $readinessSnapshot['family_code'],
            ], request: $request);
        }

        $this->securityAudit->record('islamic.compliance.reviewed', actor: $actor, properties: [
            'review_public_id' => $this->rowString($row, 'public_id'),
            'status' => $this->rowString($row, 'status'),
        ], request: $request);

        return $this->respondSuccess($this->complianceReviewPayload($row), 'Compliance review completed');
    }

    public function listComplianceCases(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $query = DB::table('islamic_compliance_cases as c')
            ->leftJoin('islamic_compliance_case_blockers as b', function ($join): void {
                $join->on('b.case_id', '=', 'c.id')->where('b.is_active', true);
            })
            ->select([
                'c.id',
                'c.public_id',
                'c.subject_type',
                'c.subject_public_id',
                'c.reason_code',
                'c.risk_level',
                'c.checklist_version',
                'c.status',
                'c.blocking_mode',
                'c.latest_decision',
                'c.latest_decided_at',
                'c.due_at',
                'c.closed_at',
                DB::raw('COUNT(b.id) as active_blockers_count'),
            ])
            ->groupBy([
                'c.id',
                'c.public_id',
                'c.subject_type',
                'c.subject_public_id',
                'c.reason_code',
                'c.risk_level',
                'c.checklist_version',
                'c.status',
                'c.blocking_mode',
                'c.latest_decision',
                'c.latest_decided_at',
                'c.due_at',
                'c.closed_at',
            ])
            ->orderByDesc('c.id');

        if (is_string($request->query('subject_type')) && $request->query('subject_type') !== '') {
            $query->where('c.subject_type', $request->query('subject_type'));
        }
        if (is_string($request->query('subject_public_id')) && $request->query('subject_public_id') !== '') {
            $query->where('c.subject_public_id', $request->query('subject_public_id'));
        }
        if (is_string($request->query('risk_level')) && $request->query('risk_level') !== '') {
            $query->where('c.risk_level', $request->query('risk_level'));
        }
        if (is_string($request->query('status')) && $request->query('status') !== '') {
            $query->where('c.status', $request->query('status'));
        }
        if (is_string($request->query('decision')) && $request->query('decision') !== '') {
            $query->where('c.latest_decision', $request->query('decision'));
        }
        if ($request->boolean('overdue')) {
            $query->whereNotNull('c.due_at')->where('c.due_at', '<', now());
        }
        if ($request->query('blocker_active') !== null) {
            $wantActive = filter_var($request->query('blocker_active'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($wantActive === true) {
                $query->havingRaw('COUNT(b.id) > 0');
            } elseif ($wantActive === false) {
                $query->havingRaw('COUNT(b.id) = 0');
            }
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($builder) use ($term): void {
                $builder->where('c.public_id', 'ilike', '%'.$term.'%')
                    ->orWhere('c.subject_type', 'ilike', '%'.$term.'%')
                    ->orWhere('c.subject_public_id', 'ilike', '%'.$term.'%')
                    ->orWhere('c.reason_code', 'ilike', '%'.$term.'%')
                    ->orWhere('c.risk_level', 'ilike', '%'.$term.'%')
                    ->orWhere('c.status', 'ilike', '%'.$term.'%')
                    ->orWhere('c.latest_decision', 'ilike', '%'.$term.'%');
            });
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);
        $total = (clone $query)->count();
        $rows = $query->forPage($page, $perPage)->get();

        return $this->respondSuccess(
            $rows->map(fn (object $row): array => $this->complianceCasePayload($row))->all(),
            'Compliance cases retrieved',
            meta: [
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => (int) ceil(max(1, $total) / $perPage),
                ],
            ],
        );
    }

    public function showProductReadiness(Request $request, string $productPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $product = DB::table('islamic_products')->where('public_id', $productPublicId)->first();
        if (! is_object($product)) {
            return $this->respondNotFound('Islamic product not found.');
        }

        $readiness = $this->readiness->evaluate($product, null, $actor);
        $latestSnapshot = DB::table('islamic_product_readiness_snapshots')
            ->where('islamic_product_id', $this->rowInt($product, 'id'))
            ->orderByDesc('id')
            ->first();

        return $this->respondSuccess([
            'product_public_id' => $productPublicId,
            'status' => $readiness['overall_status'],
            'family_code' => $readiness['family_code'],
            'evaluated_at' => $readiness['evaluated_at'],
            'gates' => $readiness['gates'],
            'failures_by_gate' => $readiness['failures_by_gate'],
            'missing_items' => $readiness['missing_items'],
            'latest_snapshot' => is_object($latestSnapshot) ? $this->readinessSnapshotPayload($latestSnapshot) : null,
        ], 'Islamic product readiness retrieved');
    }

    public function listProductReadinessSnapshots(Request $request, string $productPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $product = DB::table('islamic_products')->where('public_id', $productPublicId)->first(['id']);
        if (! is_object($product)) {
            return $this->respondNotFound('Islamic product not found.');
        }

        $query = DB::table('islamic_product_readiness_snapshots')
            ->where('islamic_product_id', $this->rowInt($product, 'id'))
            ->orderByDesc('id');

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($builder) use ($term): void {
                $builder->where('public_id', 'ilike', '%'.$term.'%')
                    ->orWhere('family_code', 'ilike', '%'.$term.'%')
                    ->orWhere('overall_status', 'ilike', '%'.$term.'%')
                    ->orWhere('snapshot_hash', 'ilike', '%'.$term.'%');
            });
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);
        $total = (clone $query)->count();
        $snapshots = $query->forPage($page, $perPage)->get();

        return $this->respondSuccess(
            [
                'readiness_snapshots' => $snapshots->map(fn (object $row): array => $this->readinessSnapshotPayload($row))->all(),
            ],
            'Islamic product readiness snapshots retrieved',
            meta: [
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => (int) ceil(max(1, $total) / $perPage),
                ],
            ],
        );
    }

    public function showComplianceCase(Request $request, string $casePublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $row = DB::table('islamic_compliance_cases')->where('public_id', $casePublicId)->first();
        if (! is_object($row)) {
            return $this->respondNotFound('Compliance case not found.');
        }

        return $this->respondSuccess($this->complianceCasePayload($row), 'Compliance case retrieved');
    }

    public function showComplianceCaseTimeline(Request $request, string $casePublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $case = DB::table('islamic_compliance_cases')->where('public_id', $casePublicId)->first(['id']);
        if (! is_object($case)) {
            return $this->respondNotFound('Compliance case not found.');
        }
        $query = DB::table('islamic_compliance_case_decisions')
            ->where('case_id', $this->rowInt($case, 'id'))
            ->orderBy('decided_at')
            ->orderBy('id')
            ->select([
                'public_id',
                'decision',
                'decision_comments',
                'conditions',
                'decided_at',
                'effective_from',
                'effective_to',
                'metadata',
            ]);
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($builder) use ($term): void {
                $builder->where('public_id', 'ilike', '%'.$term.'%')
                    ->orWhere('decision', 'ilike', '%'.$term.'%')
                    ->orWhere('decision_comments', 'ilike', '%'.$term.'%');
            });
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);
        $total = (clone $query)->count();
        $events = $query->forPage($page, $perPage)->get();

        return $this->respondSuccess([
            'timeline_events' => $events->map(function (object $event): array {
                return [
                    'public_id' => $this->rowString($event, 'public_id'),
                    'decision' => $this->rowString($event, 'decision'),
                    'decision_comments' => $this->nullableString(($event->decision_comments ?? null)),
                    'conditions' => $this->decodeJsonObject($event->conditions ?? null),
                    'decided_at' => $this->nullableString(($event->decided_at ?? null)),
                    'effective_from' => $this->nullableString(($event->effective_from ?? null)),
                    'effective_to' => $this->nullableString(($event->effective_to ?? null)),
                    'metadata' => $this->decodeJsonObject($event->metadata ?? null),
                ];
            })->all(),
        ], 'Compliance case timeline retrieved', meta: [
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil(max(1, $total) / $perPage),
            ],
        ]);
    }

    public function complianceCaseSummary(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $summary = [
            'open' => DB::table('islamic_compliance_cases')->where('status', 'open')->count(),
            'in_review' => DB::table('islamic_compliance_cases')->where('status', 'in_review')->count(),
            'blocked' => DB::table('islamic_compliance_cases')->where('status', 'blocked')->count(),
            'resolved' => DB::table('islamic_compliance_cases')->where('status', 'resolved')->count(),
            'archived' => DB::table('islamic_compliance_cases')->where('status', 'archived')->count(),
            'overdue' => DB::table('islamic_compliance_cases')->whereNotNull('due_at')->where('due_at', '<', now())->count(),
            'active_blockers' => DB::table('islamic_compliance_case_blockers')->where('is_active', true)->count(),
        ];

        return $this->respondSuccess($summary, 'Compliance case summary retrieved');
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(object $row): array
    {
        $contractType = $this->rowString($row, 'contract_type');
        $familyCode = IslamicProductFamilyRegistry::familyForContractType($contractType) ?? $contractType;
        $rules = $this->decodeJsonObject(((array) $row)['rules'] ?? null) ?? [];
        $transferOption = (bool) ($rules['transfer_option'] ?? false);

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'code' => $this->rowString($row, 'code'),
            'name' => $this->rowString($row, 'name'),
            'contract_type' => $contractType,
            'variant_classification' => $familyCode,
            'transfer_capability' => [
                'enabled' => $transferOption,
                'requires_transfer_workflow' => in_array($familyCode, ['ijara', 'ijara_wa_iqtina'], true),
            ],
            'rules' => $rules,
            'status' => $this->rowString($row, 'status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function complianceReviewPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'status' => $this->rowString($row, 'status'),
            'decision' => $this->rowString($row, 'decision'),
        ];
    }

    /** @return array<string, mixed> */
    private function complianceCasePayload(object $row): array
    {
        $activeCount = (int) (((array) $row)['active_blockers_count'] ?? 0);
        $dueAt = $this->nullableString(((array) $row)['due_at'] ?? null);

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'subject_type' => $this->rowString($row, 'subject_type'),
            'subject_public_id' => $this->rowString($row, 'subject_public_id'),
            'reason_code' => $this->rowString($row, 'reason_code'),
            'risk_level' => $this->rowString($row, 'risk_level'),
            'checklist_version' => $this->rowString($row, 'checklist_version'),
            'status' => $this->rowString($row, 'status'),
            'blocking_mode' => $this->rowString($row, 'blocking_mode'),
            'latest_decision' => $this->nullableString(((array) $row)['latest_decision'] ?? null),
            'latest_decided_at' => $this->nullableString(((array) $row)['latest_decided_at'] ?? null),
            'due_at' => $dueAt,
            'overdue' => $dueAt !== null && $dueAt < now()->toDateTimeString(),
            'closed_at' => $this->nullableString(((array) $row)['closed_at'] ?? null),
            'active_blocker' => $activeCount > 0,
            'active_blockers_count' => $activeCount,
            'metadata' => $this->decodeJsonObject(((array) $row)['metadata'] ?? null),
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

    private function productFamilyForContractType(string $contractType): ?string
    {
        return IslamicProductFamilyRegistry::familyForContractType($contractType);
    }

    /**
     * @param array{
     *   family_code: string,
     *   evaluated_at: string,
     *   gates: array<int, array<string, mixed>>,
     *   failures_by_gate: array<string, array<int, string>>,
     *   missing_items: array<int, string>
     * } $readiness
     * @return array{snapshot_public_id: string, snapshot_hash: string, family_code: string}
     */
    private function storeReadinessSnapshot(object $product, User $actor, string $reviewPublicId, array $readiness): array
    {
        $payload = [
            'family_code' => $readiness['family_code'],
            'evaluated_at' => $readiness['evaluated_at'],
            'gates' => $readiness['gates'],
            'failures_by_gate' => $readiness['failures_by_gate'],
            'missing_items' => $readiness['missing_items'],
        ];

        try {
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidArgumentException('Readiness snapshot could not be serialized.');
        }

        $snapshotPublicId = (string) Str::ulid();
        $snapshotHash = hash('sha256', $payloadJson);
        DB::table('islamic_product_readiness_snapshots')->insert([
            'public_id' => $snapshotPublicId,
            'islamic_product_id' => $this->rowInt($product, 'id'),
            'review_public_id' => $reviewPublicId,
            'family_code' => $readiness['family_code'],
            'checklist_template_version' => 'if031-v1',
            'gate_results' => $payloadJson,
            'snapshot_hash' => $snapshotHash,
            'created_by_user_id' => $actor->id,
            'evaluated_at' => $readiness['evaluated_at'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->securityAudit->record('islamic.product.readiness.snapshot_stored', actor: $actor, properties: [
            'review_public_id' => $reviewPublicId,
            'snapshot_public_id' => $snapshotPublicId,
            'snapshot_hash' => $snapshotHash,
            'family_code' => $readiness['family_code'],
        ]);

        return [
            'snapshot_public_id' => $snapshotPublicId,
            'snapshot_hash' => $snapshotHash,
            'family_code' => $readiness['family_code'],
        ];
    }

    /** @return array<string, mixed> */
    private function readinessSnapshotPayload(object $row): array
    {
        $gateResultsRaw = $this->nullableString(((array) $row)['gate_results'] ?? null);
        $gateResults = $gateResultsRaw !== null ? json_decode($gateResultsRaw, true) : null;

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'review_public_id' => $this->nullableString(((array) $row)['review_public_id'] ?? null),
            'family_code' => $this->rowString($row, 'family_code'),
            'checklist_template_version' => $this->rowString($row, 'checklist_template_version'),
            'snapshot_hash' => $this->rowString($row, 'snapshot_hash'),
            'evaluated_at' => $this->nullableString(((array) $row)['evaluated_at'] ?? null),
            'created_at' => $this->nullableString(((array) $row)['created_at'] ?? null),
            'gate_results' => is_array($gateResults) ? $gateResults : null,
        ];
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
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

    /** @return array<string, mixed>|null */
    private function decodeJsonObject(mixed $value): ?array
    {
        if (is_array($value)) {
            return $this->normalizeJsonObject($value);
        }
        if (! is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $this->normalizeJsonObject($decoded) : null;
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>|null
     */
    private function normalizeJsonObject(array $value): ?array
    {
        $normalized = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                return null;
            }
            $normalized[$key] = $item;
        }

        return $normalized;
    }
}
