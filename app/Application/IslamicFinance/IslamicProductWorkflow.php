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

final class IslamicProductWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly IslamicProductReadinessService $readiness,
        private readonly IslamicShariaAuthorityService $shariaAuthority,
        private readonly IslamicApprovalWorkflowService $approvalWorkflow,
        private readonly IslamicComplianceCaseService $complianceCases,
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
            'contract_type' => ['required', Rule::in(['murabaha'])],
            'default_margin_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'rules' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
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
            return $this->respondUnprocessable(errors: ['islamic_product' => ['Product could not be reloaded.']]);
        }

        $this->securityAudit->record('islamic.product.created', actor: $actor, properties: [
            'product_public_id' => $this->rowString($row, 'public_id'),
            'code' => $this->rowString($row, 'code'),
            'contract_type' => $this->rowString($row, 'contract_type'),
        ], request: $request);

        return $this->respondCreated($this->productPayload($row), 'Islamic product created');
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
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($reviewPublicId, $validated, $actor): object {
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
                        $failures = $this->readiness->activationFailures($product);

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

            if (isset($failure->failures['islamic_sharia_authority'])) {
                $this->securityAudit->record('islamic.sharia_authority.decision_blocked', actor: $actor, properties: [
                    'review_public_id' => $reviewPublicId,
                    'decision_type' => IslamicShariaAuthorityService::DECISION_TYPE_PRODUCT_COMPLIANCE_APPROVAL,
                    'reasons' => $failure->failures['islamic_sharia_authority'],
                ], request: $request);
            }

            return $this->respondUnprocessable(errors: $failure->failures);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_compliance_review' => [$exception->getMessage()]]);
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
            $query->where('c.subject_type', (string) $request->query('subject_type'));
        }
        if (is_string($request->query('subject_public_id')) && $request->query('subject_public_id') !== '') {
            $query->where('c.subject_public_id', (string) $request->query('subject_public_id'));
        }
        if (is_string($request->query('risk_level')) && $request->query('risk_level') !== '') {
            $query->where('c.risk_level', (string) $request->query('risk_level'));
        }
        if (is_string($request->query('status')) && $request->query('status') !== '') {
            $query->where('c.status', (string) $request->query('status'));
        }
        if (is_string($request->query('decision')) && $request->query('decision') !== '') {
            $query->where('c.latest_decision', (string) $request->query('decision'));
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

        $rows = $query->get();

        return $this->respondSuccess(
            $rows->map(fn (object $row): array => $this->complianceCasePayload($row))->all(),
            'Compliance cases retrieved'
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
        $events = DB::table('islamic_compliance_case_decisions')
            ->where('case_id', $this->rowInt($case, 'id'))
            ->orderBy('decided_at')
            ->orderBy('id')
            ->get([
                'public_id',
                'decision',
                'decision_comments',
                'conditions',
                'decided_at',
                'effective_from',
                'effective_to',
                'metadata',
            ]);

        return $this->respondSuccess($events->map(function (object $event): array {
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
        })->all(), 'Compliance case timeline retrieved');
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
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'code' => $this->rowString($row, 'code'),
            'name' => $this->rowString($row, 'name'),
            'contract_type' => $this->rowString($row, 'contract_type'),
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
        $map = [
            'murabaha' => 'mourabaha',
            'mourabaha' => 'mourabaha',
            'ijara' => 'ijara',
            'ijara_wa_iqtina' => 'ijara_wa_iqtina',
            'salam' => 'salam',
            'istisnaa' => 'istisnaa',
            'moudaraba' => 'moudaraba',
            'moucharaka' => 'moucharaka',
        ];

        return $map[$contractType] ?? null;
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
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
