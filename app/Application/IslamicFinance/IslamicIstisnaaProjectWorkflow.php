<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Http\Controllers\BaseController;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class IslamicIstisnaaProjectWorkflow extends BaseController
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_CANCELLED = 'cancelled';

    public const PROJECT_TERMINAL_STATUSES = [self::STATUS_ACCEPTED, self::STATUS_CANCELLED];

    public const INSPECTION_APPROVED = 'approved';

    public const INSPECTION_REJECTED = 'rejected';

    public const INSPECTION_NEEDS_REWORK = 'needs_rework';

    public const INSPECTION_PENDING = 'pending';

    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly IslamicScreeningPolicyService $screening,
    ) {}

    private function requirePlatformAdmin(Request $request): bool
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin');
    }

    public function storeProject(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'islamic_financing_public_id' => ['sometimes', 'nullable', 'string', 'exists:islamic_financings,public_id'],
            'project_specification' => ['required', 'string', 'max:2000'],
            'contractor_reference' => ['required', 'string', 'max:128'],
            'customer_reference' => ['required', 'string', 'max:128'],
            'site_location' => ['required', 'string', 'max:255'],
            'inspection_rules' => ['sometimes', 'nullable', 'array'],
            'acceptance_criteria' => ['sometimes', 'nullable', 'array'],
            'parallel_supplier_reference' => ['sometimes', 'nullable', 'string', 'max:128'],
            'parallel_supplier_approved' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($validated): object {
                $financingId = null;
                $financingPublicId = is_string($validated['islamic_financing_public_id'] ?? null) ? $validated['islamic_financing_public_id'] : null;
                if ($financingPublicId !== null && $financingPublicId !== '') {
                    $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->lockForUpdate()->first(['id', 'status']);
                    if (! is_object($financing) || ! is_numeric($financing->id)) {
                        throw new InvalidArgumentException('Linked Islamic financing is invalid.');
                    }
                    if (is_string($financing->status ?? null) && $financing->status !== 'draft') {
                        throw new InvalidArgumentException('Istisnaa projects can only be linked to draft financings.');
                    }
                    $financingId = (int) $financing->id;
                }
                $id = DB::table('islamic_istisnaa_projects')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financing_id' => $financingId,
                    'project_specification' => (string) $validated['project_specification'],
                    'contractor_reference' => (string) $validated['contractor_reference'],
                    'customer_reference' => (string) $validated['customer_reference'],
                    'site_location' => (string) $validated['site_location'],
                    'inspection_rules' => isset($validated['inspection_rules']) && is_array($validated['inspection_rules']) ? json_encode($validated['inspection_rules'], JSON_THROW_ON_ERROR) : null,
                    'acceptance_criteria' => isset($validated['acceptance_criteria']) && is_array($validated['acceptance_criteria']) ? json_encode($validated['acceptance_criteria'], JSON_THROW_ON_ERROR) : null,
                    'parallel_supplier_reference' => is_string($validated['parallel_supplier_reference'] ?? null) && $validated['parallel_supplier_reference'] !== '' ? $validated['parallel_supplier_reference'] : null,
                    'parallel_supplier_approved' => (bool) ($validated['parallel_supplier_approved'] ?? false),
                    'status' => self::STATUS_DRAFT,
                    'metadata' => isset($validated['metadata']) && is_array($validated['metadata']) ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_istisnaa_projects')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Project could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_istisnaa_project' => [$exception->getMessage()]]);
        }
        $this->securityAudit->record('islamic.istisnaa_project.created', actor: $actor, properties: [
            'project_public_id' => $this->rowString($row, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->projectPayload($row), 'Istisnaa project registered');
    }

    public function showProject(Request $request, string $projectPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $row = DB::table('islamic_istisnaa_projects')->where('public_id', $projectPublicId)->first();
        if (! is_object($row)) {
            return $this->respondNotFound('Istisnaa project not found.');
        }

        return $this->respondSuccess($this->projectPayload($row));
    }

    public function showTimeline(Request $request, string $projectPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $project = DB::table('islamic_istisnaa_projects')->where('public_id', $projectPublicId)->first();
        if (! is_object($project)) {
            return $this->respondNotFound('Istisnaa project not found.');
        }

        $events = $this->istisnaaTimelineEvents($this->rowInt($project, 'id'));
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = mb_strtolower(trim($search));
            $events = array_values(array_filter($events, static function (array $event) use ($term): bool {
                $haystack = mb_strtolower(json_encode($event, JSON_THROW_ON_ERROR));

                return str_contains($haystack, $term);
            }));
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);
        $total = count($events);
        $slice = array_slice($events, ($page - 1) * $perPage, $perPage);

        return $this->respondSuccess([
            'project' => $this->projectPayload($project),
            'timeline_events' => $slice,
        ], 'Istisnaa timeline retrieved', meta: [
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil(max(1, $total) / $perPage),
            ],
        ]);
    }

    public function storeMilestone(Request $request, string $projectPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'milestone_code' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:255'],
            'planned_amount_minor' => ['required', 'integer', 'min:1'],
            'due_date' => ['required', 'date'],
            'inspection_requirement' => ['sometimes', 'nullable', 'array'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        try {
            $row = DB::transaction(function () use ($projectPublicId, $validated): object {
                $project = DB::table('islamic_istisnaa_projects')->where('public_id', $projectPublicId)->lockForUpdate()->first();
                if (! is_object($project)) {
                    throw new InvalidArgumentException('Istisnaa project is invalid.');
                }
                if (in_array($this->rowString($project, 'status'), self::PROJECT_TERMINAL_STATUSES, true)) {
                    throw new InvalidArgumentException('Cannot add milestones to a terminal project.');
                }
                $id = DB::table('islamic_istisnaa_milestones')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_istisnaa_project_id' => $this->rowInt($project, 'id'),
                    'milestone_code' => (string) $validated['milestone_code'],
                    'title' => (string) $validated['title'],
                    'planned_amount_minor' => (int) $validated['planned_amount_minor'],
                    'paid_amount_minor' => 0,
                    'due_date' => (string) $validated['due_date'],
                    'inspection_requirement' => isset($validated['inspection_requirement']) && is_array($validated['inspection_requirement']) ? json_encode($validated['inspection_requirement'], JSON_THROW_ON_ERROR) : null,
                    'inspection_status' => self::INSPECTION_PENDING,
                    'payment_status' => 'unpaid',
                    'status' => 'planned',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('islamic_istisnaa_projects')
                    ->where('id', $this->rowInt($project, 'id'))
                    ->increment('total_planned_amount_minor', (int) $validated['planned_amount_minor'], ['updated_at' => now()]);
                $row = DB::table('islamic_istisnaa_milestones')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Milestone could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_istisnaa_milestone' => [$exception->getMessage()]]);
        }

        return $this->respondCreated($this->milestonePayload($row), 'Istisnaa milestone added');
    }

    public function storeInspection(Request $request, string $milestonePublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'decision' => ['required', 'string', 'in:'.self::INSPECTION_APPROVED.','.self::INSPECTION_REJECTED.','.self::INSPECTION_NEEDS_REWORK],
            'evidence_document_public_id' => ['required', 'string', 'exists:documents,public_id'],
            'comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        try {
            $row = DB::transaction(function () use ($milestonePublicId, $validated, $actor): object {
                $milestone = DB::table('islamic_istisnaa_milestones')->where('public_id', $milestonePublicId)->lockForUpdate()->first();
                if (! is_object($milestone)) {
                    throw new InvalidArgumentException('Istisnaa milestone is invalid.');
                }
                if ($this->rowString($milestone, 'payment_status') === 'paid_in_full') {
                    throw new InvalidArgumentException('Cannot record inspection on a fully-paid milestone.');
                }
                $inspectionId = DB::table('islamic_istisnaa_inspections')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_istisnaa_milestone_id' => $this->rowInt($milestone, 'id'),
                    'decision' => (string) $validated['decision'],
                    'evidence_document_public_id' => (string) $validated['evidence_document_public_id'],
                    'inspector_user_id' => $actor->id,
                    'comments' => is_string($validated['comments'] ?? null) ? $validated['comments'] : null,
                    'decided_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('islamic_istisnaa_milestones')
                    ->where('id', $this->rowInt($milestone, 'id'))
                    ->update([
                        'inspection_status' => $validated['decision'],
                        'updated_at' => now(),
                    ]);
                $row = DB::table('islamic_istisnaa_inspections')->where('id', $inspectionId)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Inspection could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_istisnaa_inspection' => [$exception->getMessage()]]);
        }
        $this->securityAudit->record('islamic.istisnaa_milestone.inspection_recorded', actor: $actor, properties: [
            'milestone_public_id' => $milestonePublicId,
            'inspection_public_id' => $this->rowString($row, 'public_id'),
            'decision' => $this->rowString($row, 'decision'),
        ], request: $request);

        return $this->respondCreated($this->inspectionPayload($row), 'Inspection recorded');
    }

    public function storePayment(Request $request, string $milestonePublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'amount_minor' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($milestonePublicId, $validated, $actor): object {
                $milestone = DB::table('islamic_istisnaa_milestones')->where('public_id', $milestonePublicId)->lockForUpdate()->first();
                if (! is_object($milestone)) {
                    throw new InvalidArgumentException('Istisnaa milestone is invalid.');
                }
                $project = DB::table('islamic_istisnaa_projects')
                    ->where('id', (int) $milestone->islamic_istisnaa_project_id)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($project)) {
                    throw new InvalidArgumentException('Istisnaa project is invalid.');
                }

                $inspectionStatus = $this->rowString($milestone, 'inspection_status');
                if ($inspectionStatus !== self::INSPECTION_APPROVED) {
                    $this->securityAudit->record('islamic.istisnaa_milestone.payment_blocked', actor: $actor, properties: [
                        'milestone_public_id' => $milestonePublicId,
                        'reason' => 'inspection_not_approved',
                        'inspection_status' => $inspectionStatus,
                    ]);
                    throw new InvalidArgumentException('Milestone payment blocked: inspection must be approved (IF-042 payment gate).');
                }
                $screening = $this->evaluateProjectApprovalScreening(project: $project, actor: $actor);
                if ($screening['result'] === 'fail') {
                    throw new InvalidArgumentException('Milestone payment blocked by project approval screening result (IF-042 screening gate).');
                }
                if ($screening['result'] === 'manual_review') {
                    throw new InvalidArgumentException('Milestone payment requires manual compliance review for project approval screening (IF-042 screening gate).');
                }

                $latestApprovedInspection = DB::table('islamic_istisnaa_inspections')
                    ->where('islamic_istisnaa_milestone_id', $this->rowInt($milestone, 'id'))
                    ->where('decision', self::INSPECTION_APPROVED)
                    ->orderByDesc('id')
                    ->first(['public_id']);
                if (! is_object($latestApprovedInspection)) {
                    throw new InvalidArgumentException('No approved inspection found for milestone.');
                }

                $planned = (int) $milestone->planned_amount_minor;
                $paid = (int) $milestone->paid_amount_minor;
                $newAmount = (int) $validated['amount_minor'];
                if ($paid + $newAmount > $planned) {
                    throw new InvalidArgumentException('Payment amount exceeds remaining approved milestone amount.');
                }

                $idempotency = (string) $validated['idempotency_key'];
                if (DB::table('islamic_istisnaa_payments')->where('idempotency_key', $idempotency)->exists()) {
                    throw new InvalidArgumentException('Payment with idempotency_key already posted.');
                }

                $paymentId = DB::table('islamic_istisnaa_payments')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_istisnaa_milestone_id' => $this->rowInt($milestone, 'id'),
                    'amount_minor' => $newAmount,
                    'idempotency_key' => $idempotency,
                    'inspection_public_id' => $this->rowString($latestApprovedInspection, 'public_id'),
                    'actor_user_id' => $actor->id,
                    'posted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $finalPaid = $paid + $newAmount;
                DB::table('islamic_istisnaa_milestones')
                    ->where('id', $this->rowInt($milestone, 'id'))
                    ->update([
                        'paid_amount_minor' => $finalPaid,
                        'payment_status' => $finalPaid === $planned ? 'paid_in_full' : 'partially_paid',
                        'status' => $finalPaid === $planned ? 'settled' : 'in_progress',
                        'updated_at' => now(),
                    ]);
                DB::table('islamic_istisnaa_projects')
                    ->where('id', (int) $milestone->islamic_istisnaa_project_id)
                    ->increment('total_paid_amount_minor', $newAmount, [
                        'screening_result_public_id' => $screening['screening_result_public_id'],
                        'updated_at' => now(),
                    ]);
                $row = DB::table('islamic_istisnaa_payments')->where('id', $paymentId)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Payment could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_istisnaa_payment' => [$exception->getMessage()]]);
        }
        $this->securityAudit->record('islamic.istisnaa_milestone.payment_released', actor: $actor, properties: [
            'milestone_public_id' => $milestonePublicId,
            'payment_public_id' => $this->rowString($row, 'public_id'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
        ], request: $request);

        return $this->respondCreated($this->paymentPayload($row), 'Milestone payment released');
    }

    public function storeVariation(Request $request, string $projectPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'target_type' => ['required', 'string', 'in:milestone,project'],
            'target_public_id' => ['required', 'string', 'max:64'],
            'after_snapshot' => ['required', 'array'],
            'reason' => ['required', 'string', 'max:2000'],
            'approval_evidence_document_public_id' => ['required', 'string', 'exists:documents,public_id'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        $targetType = (string) $validated['target_type'];
        $targetPublicId = (string) $validated['target_public_id'];

        try {
            $row = DB::transaction(function () use ($projectPublicId, $targetType, $targetPublicId, $validated, $actor): object {
                $project = DB::table('islamic_istisnaa_projects')->where('public_id', $projectPublicId)->lockForUpdate()->first();
                if (! is_object($project)) {
                    throw new InvalidArgumentException('Istisnaa project is invalid.');
                }
                if (in_array($this->rowString($project, 'status'), self::PROJECT_TERMINAL_STATUSES, true)) {
                    throw new InvalidArgumentException('Cannot apply variation to a terminal project.');
                }

                $beforeSnapshot = [];
                $afterSnapshot = is_array($validated['after_snapshot']) ? $validated['after_snapshot'] : [];
                if ($targetType === 'milestone') {
                    $milestone = DB::table('islamic_istisnaa_milestones')->where('public_id', $targetPublicId)->lockForUpdate()->first();
                    if (! is_object($milestone) || (int) $milestone->islamic_istisnaa_project_id !== $this->rowInt($project, 'id')) {
                        throw new InvalidArgumentException('Target milestone is invalid.');
                    }
                    if ((int) $milestone->paid_amount_minor > 0) {
                        throw new InvalidArgumentException('Cannot apply variation: posted payment facts are immutable (IF-042 variation policy).');
                    }
                    if ($this->rowString($milestone, 'payment_status') === 'paid_in_full') {
                        throw new InvalidArgumentException('Cannot apply variation to a fully-paid milestone.');
                    }
                    $beforeSnapshot = [
                        'planned_amount_minor' => (int) $milestone->planned_amount_minor,
                        'due_date' => $this->rowString($milestone, 'due_date'),
                        'title' => $this->rowString($milestone, 'title'),
                    ];
                    $update = ['updated_at' => now()];
                    if (isset($afterSnapshot['planned_amount_minor']) && is_numeric($afterSnapshot['planned_amount_minor'])) {
                        $delta = (int) $afterSnapshot['planned_amount_minor'] - (int) $milestone->planned_amount_minor;
                        $update['planned_amount_minor'] = (int) $afterSnapshot['planned_amount_minor'];
                        DB::table('islamic_istisnaa_projects')
                            ->where('id', $this->rowInt($project, 'id'))
                            ->increment('total_planned_amount_minor', $delta, ['updated_at' => now()]);
                    }
                    if (isset($afterSnapshot['due_date']) && is_string($afterSnapshot['due_date'])) {
                        $update['due_date'] = $afterSnapshot['due_date'];
                    }
                    if (isset($afterSnapshot['title']) && is_string($afterSnapshot['title'])) {
                        $update['title'] = $afterSnapshot['title'];
                    }
                    DB::table('islamic_istisnaa_milestones')->where('id', (int) $milestone->id)->update($update);
                } else {
                    if ((int) ($project->total_paid_amount_minor ?? 0) > 0) {
                        throw new InvalidArgumentException('Cannot apply project-level variation: payments already posted (IF-042 variation policy).');
                    }
                    $beforeSnapshot = [
                        'project_specification' => $this->rowString($project, 'project_specification'),
                        'site_location' => $this->rowString($project, 'site_location'),
                    ];
                    $update = ['updated_at' => now()];
                    if (isset($afterSnapshot['project_specification']) && is_string($afterSnapshot['project_specification'])) {
                        $update['project_specification'] = $afterSnapshot['project_specification'];
                    }
                    if (isset($afterSnapshot['site_location']) && is_string($afterSnapshot['site_location'])) {
                        $update['site_location'] = $afterSnapshot['site_location'];
                    }
                    DB::table('islamic_istisnaa_projects')->where('id', $this->rowInt($project, 'id'))->update($update);
                }

                $variationId = DB::table('islamic_istisnaa_variation_orders')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_istisnaa_project_id' => $this->rowInt($project, 'id'),
                    'target_type' => $targetType,
                    'target_public_id' => $targetPublicId,
                    'before_snapshot' => json_encode($beforeSnapshot, JSON_THROW_ON_ERROR),
                    'after_snapshot' => json_encode(array_merge($afterSnapshot, [
                        '_approval_evidence_document_public_id' => (string) $validated['approval_evidence_document_public_id'],
                    ]), JSON_THROW_ON_ERROR),
                    'reason' => (string) $validated['reason'],
                    'actor_user_id' => $actor->id,
                    'applied_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_istisnaa_variation_orders')->where('id', $variationId)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Variation could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_istisnaa_variation' => [$exception->getMessage()]]);
        }
        $this->securityAudit->record('islamic.istisnaa_variation.approved', actor: $actor, properties: [
            'project_public_id' => $projectPublicId,
            'variation_public_id' => $this->rowString($row, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->variationPayload($row), 'Variation order applied');
    }

    public function approveParallelSupplier(Request $request, string $projectPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'approval_evidence_document_public_id' => ['required', 'string', 'exists:documents,public_id'],
            'comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        try {
            $row = DB::transaction(function () use ($projectPublicId, $validated): object {
                $project = DB::table('islamic_istisnaa_projects')->where('public_id', $projectPublicId)->lockForUpdate()->first();
                if (! is_object($project)) {
                    throw new InvalidArgumentException('Istisnaa project is invalid.');
                }
                if (! is_string($project->parallel_supplier_reference ?? null) || $project->parallel_supplier_reference === '') {
                    throw new InvalidArgumentException('Project has no parallel supplier reference to approve.');
                }
                if ((bool) ($project->parallel_supplier_approved ?? false) === true) {
                    throw new InvalidArgumentException('Parallel supplier reference is already approved.');
                }
                $metadata = is_string($project->metadata ?? null) && $project->metadata !== '' ? $project->metadata : '{}';
                $decoded = json_decode($metadata, true);
                $decoded = is_array($decoded) ? $decoded : [];
                $decoded['parallel_supplier_approval'] = [
                    'evidence_document_public_id' => $validated['approval_evidence_document_public_id'],
                    'comments' => is_string($validated['comments'] ?? null) ? $validated['comments'] : null,
                    'approved_at' => now()->toIso8601String(),
                ];
                DB::table('islamic_istisnaa_projects')->where('id', $this->rowInt($project, 'id'))->update([
                    'parallel_supplier_approved' => true,
                    'metadata' => json_encode($decoded, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_istisnaa_projects')->where('id', $this->rowInt($project, 'id'))->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Project could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_istisnaa_project' => [$exception->getMessage()]]);
        }
        $this->securityAudit->record('islamic.istisnaa_project.parallel_supplier_approved', actor: $actor, properties: [
            'project_public_id' => $projectPublicId,
            'parallel_supplier_reference' => $this->rowNullableString($row, 'parallel_supplier_reference'),
        ], request: $request);

        return $this->respondSuccess($this->projectPayload($row), 'Parallel supplier reference approved');
    }

    public function acceptProject(Request $request, string $projectPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $validated = Validator::make($request->all(), [
            'acceptance_evidence_document_public_id' => ['required', 'string', 'exists:documents,public_id'],
            'comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }
        try {
            $row = DB::transaction(function () use ($projectPublicId, $validated, $actor): object {
                $project = DB::table('islamic_istisnaa_projects')->where('public_id', $projectPublicId)->lockForUpdate()->first();
                if (! is_object($project)) {
                    throw new InvalidArgumentException('Istisnaa project is invalid.');
                }
                if ($this->rowString($project, 'status') === self::STATUS_ACCEPTED) {
                    throw new InvalidArgumentException('Project is already accepted.');
                }
                if (in_array($this->rowString($project, 'status'), self::PROJECT_TERMINAL_STATUSES, true)) {
                    throw new InvalidArgumentException('Project cannot be accepted from terminal status.');
                }
                $milestones = DB::table('islamic_istisnaa_milestones')
                    ->where('islamic_istisnaa_project_id', $this->rowInt($project, 'id'))
                    ->get(['id', 'payment_status', 'status']);
                if ($milestones->isEmpty()) {
                    throw new InvalidArgumentException('Project requires at least one milestone before acceptance.');
                }
                $unsettled = $milestones->filter(fn (object $m): bool => $this->rowString($m, 'payment_status') !== 'paid_in_full');
                if ($unsettled->isNotEmpty()) {
                    throw new InvalidArgumentException('Project acceptance requires all milestones paid in full (IF-042 closure rule).');
                }
                $screening = $this->evaluateProjectApprovalScreening(project: $project, actor: $actor);
                if ($screening['result'] === 'fail') {
                    throw new InvalidArgumentException('Project acceptance blocked by project approval screening result (IF-042 screening gate).');
                }
                if ($screening['result'] === 'manual_review') {
                    throw new InvalidArgumentException('Project acceptance requires manual compliance review for project approval screening (IF-042 screening gate).');
                }
                $metadata = is_string($project->metadata ?? null) && $project->metadata !== '' ? $project->metadata : '{}';
                $decoded = json_decode($metadata, true);
                $decoded = is_array($decoded) ? $decoded : [];
                $decoded['acceptance_evidence_document_public_id'] = $validated['acceptance_evidence_document_public_id'];
                if (is_string($validated['comments'] ?? null) && $validated['comments'] !== '') {
                    $decoded['acceptance_comments'] = $validated['comments'];
                }
                DB::table('islamic_istisnaa_projects')->where('id', $this->rowInt($project, 'id'))->update([
                    'status' => self::STATUS_ACCEPTED,
                    'screening_result_public_id' => $screening['screening_result_public_id'],
                    'metadata' => json_encode($decoded, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
                $row = DB::table('islamic_istisnaa_projects')->where('id', $this->rowInt($project, 'id'))->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Project could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_istisnaa_project' => [$exception->getMessage()]]);
        }
        $this->securityAudit->record('islamic.istisnaa_project.accepted', actor: $actor, properties: [
            'project_public_id' => $projectPublicId,
        ], request: $request);

        return $this->respondSuccess($this->projectPayload($row), 'Istisnaa project accepted');
    }

    public function assertProjectsReadyForApproval(int $financingId, ?User $actor = null): void
    {
        $projects = DB::table('islamic_istisnaa_projects')
            ->where('islamic_financing_id', $financingId)
            ->lockForUpdate()
            ->get();
        if ($projects->isEmpty()) {
            throw new InvalidArgumentException('Istisnaa financing requires a registered project (IF-042 activation gate).');
        }
        foreach ($projects as $project) {
            $milestoneCount = DB::table('islamic_istisnaa_milestones')->where('islamic_istisnaa_project_id', (int) $project->id)->count();
            if ($milestoneCount === 0) {
                throw new InvalidArgumentException(__('islamic_finance.istisnaa_project_requires_milestones', [
                    'public_id' => $this->rowString($project, 'public_id'),
                ]));
            }
            if (is_string($project->parallel_supplier_reference ?? null) && $project->parallel_supplier_reference !== '' && ! (bool) ($project->parallel_supplier_approved ?? false)) {
                throw new InvalidArgumentException(__('islamic_finance.istisnaa_parallel_supplier_unapproved', [
                    'public_id' => $this->rowString($project, 'public_id'),
                ]));
            }
            $screening = $this->evaluateProjectApprovalScreening(project: $project, actor: $actor);
            if ($screening['result'] === 'fail') {
                throw new InvalidArgumentException('Istisnaa financing approval blocked by project approval screening result (IF-042 screening gate).');
            }
            if ($screening['result'] === 'manual_review') {
                throw new InvalidArgumentException('Istisnaa financing approval requires manual compliance review for project approval screening (IF-042 screening gate).');
            }
            if ($screening['screening_result_public_id'] !== null) {
                DB::table('islamic_istisnaa_projects')
                    ->where('id', (int) $project->id)
                    ->update([
                        'screening_result_public_id' => $screening['screening_result_public_id'],
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * @return array{result:string, screening_result_public_id:?string, compliance_case_public_id:?string}
     */
    private function evaluateProjectApprovalScreening(object $project, ?User $actor): array
    {
        $projectPublicId = $this->rowString($project, 'public_id');
        if ($projectPublicId === '') {
            throw new InvalidArgumentException('Istisnaa project screening requires a valid project identifier.');
        }
        $supplierFlags = [];
        $contractorReference = $this->rowString($project, 'contractor_reference');
        if ($contractorReference !== '') {
            $supplierFlags[] = strtolower($contractorReference);
        }
        $parallelSupplierReference = $this->rowNullableString($project, 'parallel_supplier_reference');
        if (is_string($parallelSupplierReference) && $parallelSupplierReference !== '') {
            $supplierFlags[] = strtolower($parallelSupplierReference);
        }
        $screeningOutcome = $this->screening->evaluate(
            subjectType: 'islamic_project',
            subjectPublicId: $projectPublicId,
            contextType: 'project_approval',
            facts: [
                'scope_type' => 'product_family',
                'scope_value' => 'istisnaa',
                'agency_scope_value' => (string) $this->rowInt($project, 'agency_id'),
                'supplier_flags' => $supplierFlags,
                'sector_codes' => [strtolower($this->rowString($project, 'site_location'))],
                'project_public_id' => $projectPublicId,
            ],
            actor: $actor,
            strictPolicy: false,
        );

        return [
            'result' => is_string($screeningOutcome['result'] ?? null) ? $screeningOutcome['result'] : 'not_applicable',
            'screening_result_public_id' => is_string($screeningOutcome['public_id'] ?? null) && $screeningOutcome['public_id'] !== ''
                ? $screeningOutcome['public_id']
                : null,
            'compliance_case_public_id' => is_string($screeningOutcome['review_case_public_id'] ?? null) && $screeningOutcome['review_case_public_id'] !== ''
                ? $screeningOutcome['review_case_public_id']
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'project_specification' => $this->rowString($row, 'project_specification'),
            'contractor_reference' => $this->rowString($row, 'contractor_reference'),
            'customer_reference' => $this->rowString($row, 'customer_reference'),
            'site_location' => $this->rowString($row, 'site_location'),
            'parallel_supplier_reference' => $this->rowNullableString($row, 'parallel_supplier_reference'),
            'parallel_supplier_approved' => (bool) (((array) $row)['parallel_supplier_approved'] ?? false),
            'status' => $this->rowString($row, 'status'),
            'total_planned_amount_minor' => $this->rowInt($row, 'total_planned_amount_minor'),
            'total_paid_amount_minor' => $this->rowInt($row, 'total_paid_amount_minor'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function milestonePayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'milestone_code' => $this->rowString($row, 'milestone_code'),
            'title' => $this->rowString($row, 'title'),
            'planned_amount_minor' => $this->rowInt($row, 'planned_amount_minor'),
            'paid_amount_minor' => $this->rowInt($row, 'paid_amount_minor'),
            'due_date' => $this->rowNullableString($row, 'due_date'),
            'inspection_status' => $this->rowString($row, 'inspection_status'),
            'payment_status' => $this->rowString($row, 'payment_status'),
            'status' => $this->rowString($row, 'status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectionPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'decision' => $this->rowString($row, 'decision'),
            'evidence_document_public_id' => $this->rowString($row, 'evidence_document_public_id'),
            'comments' => $this->rowNullableString($row, 'comments'),
            'decided_at' => $this->rowNullableString($row, 'decided_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
            'idempotency_key' => $this->rowString($row, 'idempotency_key'),
            'inspection_public_id' => $this->rowString($row, 'inspection_public_id'),
            'posted_at' => $this->rowNullableString($row, 'posted_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function variationPayload(object $row): array
    {
        $before = json_decode((string) (((array) $row)['before_snapshot'] ?? 'null'), true);
        $after = json_decode((string) (((array) $row)['after_snapshot'] ?? 'null'), true);

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'target_type' => $this->rowString($row, 'target_type'),
            'target_public_id' => $this->rowString($row, 'target_public_id'),
            'before_snapshot' => is_array($before) ? $before : null,
            'after_snapshot' => is_array($after) ? $after : null,
            'reason' => $this->rowString($row, 'reason'),
            'applied_at' => $this->rowNullableString($row, 'applied_at'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function istisnaaTimelineEvents(int $projectId): array
    {
        $events = [];

        $milestones = DB::table('islamic_istisnaa_milestones')
            ->where('islamic_istisnaa_project_id', $projectId)
            ->orderBy('id')
            ->get();
        $milestoneIds = $milestones
            ->map(fn (object $row): int => $this->rowInt($row, 'id'))
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        foreach ($milestones as $row) {
            $events[] = ['type' => 'milestone'] + $this->milestonePayload($row);
        }

        if ($milestoneIds !== []) {
            foreach (DB::table('islamic_istisnaa_inspections')
                ->whereIn('islamic_istisnaa_milestone_id', $milestoneIds)
                ->orderBy('id')
                ->get() as $row) {
                $events[] = ['type' => 'inspection'] + $this->inspectionPayload($row);
            }

            foreach (DB::table('islamic_istisnaa_payments')
                ->whereIn('islamic_istisnaa_milestone_id', $milestoneIds)
                ->orderBy('id')
                ->get() as $row) {
                $events[] = ['type' => 'payment'] + $this->paymentPayload($row);
            }
        }

        foreach (DB::table('islamic_istisnaa_variation_orders')
            ->where('islamic_istisnaa_project_id', $projectId)
            ->orderBy('id')
            ->get() as $row) {
            $events[] = ['type' => 'variation'] + $this->variationPayload($row);
        }

        return $events;
    }

    private function rowString(object $row, string $field): string
    {
        $value = ((array) $row)[$field] ?? null;

        return is_string($value) ? $value : '';
    }

    private function rowNullableString(object $row, string $field): ?string
    {
        $value = ((array) $row)[$field] ?? null;

        return is_string($value) ? $value : null;
    }

    private function rowInt(object $row, string $field): int
    {
        $value = ((array) $row)[$field] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
