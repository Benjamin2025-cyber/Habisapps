<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Http\Controllers\BaseController;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\AccountingDay\AccountingDayGuard;
use App\Support\Security\SecurityAudit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class IslamicTreatmentWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly IslamicApprovalWorkflowService $approvalWorkflow,
        private readonly IslamicTreatmentRoutingService $routing,
        private readonly AccountingDayGuard $accountingDayGuard,
    ) {}

    public function indexPolicies(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'status' => ['sometimes', 'nullable', Rule::in(['draft', 'submitted', 'approved', 'suspended', 'revoked', 'expired', 'archived'])],
            'scope_type' => ['sometimes', 'nullable', Rule::in(['institution', 'agency', 'product_family', 'product'])],
        ])->validate();

        $query = DB::table('islamic_treatment_policies')->orderByDesc('id');
        foreach (['status', 'scope_type'] as $key) {
            if (is_string($validated[$key] ?? null) && $validated[$key] !== '') {
                $query->where($key, $validated[$key]);
            }
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($builder) use ($term): void {
                $builder->where('public_id', 'ilike', '%'.$term.'%')
                    ->orWhere('policy_code', 'ilike', '%'.$term.'%')
                    ->orWhere('scope_type', 'ilike', '%'.$term.'%')
                    ->orWhere('scope_value', 'ilike', '%'.$term.'%')
                    ->orWhere('status', 'ilike', '%'.$term.'%')
                    ->orWhere('purification_mode', 'ilike', '%'.$term.'%');
            });
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);
        $total = (clone $query)->count();
        $rows = $query->forPage($page, $perPage)->get();

        return $this->respondSuccess(
            [
                'treatment_policies' => $rows->map(fn (object $row): array => $this->policyPayload($row))->all(),
            ],
            'Islamic treatment policies retrieved',
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

    public function storePolicy(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'policy_code' => ['required', 'string', 'max:64'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'scope_type' => ['required', Rule::in(['institution', 'agency', 'product_family', 'product'])],
            'scope_value' => ['sometimes', 'nullable', 'string', 'max:128'],
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'zakat_enabled' => ['sometimes', 'boolean'],
            'charity_treatment_enabled' => ['sometimes', 'boolean'],
            'non_compliant_income_treatment_enabled' => ['sometimes', 'boolean'],
            'purification_mode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'required_operation_codes' => ['sometimes', 'array'],
            'required_operation_codes.*' => ['sometimes', 'string', 'max:128'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after:effective_from'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        try {
            $row = DB::transaction(function () use ($validated, $actor, $request): object {
                $this->assertPolicyShape($validated);

                $publicId = (string) Str::ulid();
                $id = DB::table('islamic_treatment_policies')->insertGetId([
                    'public_id' => $publicId,
                    'policy_code' => (string) $validated['policy_code'],
                    'version' => is_numeric($validated['version'] ?? null) ? (int) $validated['version'] : 1,
                    'scope_type' => (string) $validated['scope_type'],
                    'scope_value' => is_string($validated['scope_value'] ?? null) ? $validated['scope_value'] : null,
                    'agency_id' => $this->idByPublicId('agencies', $validated['agency_public_id'] ?? null),
                    'zakat_enabled' => (bool) ($validated['zakat_enabled'] ?? false),
                    'charity_treatment_enabled' => (bool) ($validated['charity_treatment_enabled'] ?? false),
                    'non_compliant_income_treatment_enabled' => (bool) ($validated['non_compliant_income_treatment_enabled'] ?? false),
                    'purification_mode' => is_string($validated['purification_mode'] ?? null) ? $validated['purification_mode'] : null,
                    'required_operation_codes' => isset($validated['required_operation_codes']) ? json_encode($validated['required_operation_codes'], JSON_THROW_ON_ERROR) : null,
                    'status' => 'draft',
                    'effective_from' => (string) $validated['effective_from'],
                    'effective_to' => is_string($validated['effective_to'] ?? null) ? $validated['effective_to'] : null,
                    'approved_by_user_id' => null,
                    'approved_at' => null,
                    'created_by_user_id' => $actor->id,
                    'metadata' => isset($validated['metadata']) ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->approvalWorkflow->ensureWorkflow(
                    IslamicApprovalStateMachine::SUBJECT_TREATMENT_POLICY,
                    $publicId,
                    $actor,
                    $request,
                );

                $row = DB::table('islamic_treatment_policies')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Islamic treatment policy could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_treatment_policy' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.treatment_policy.created', actor: $actor, properties: [
            'policy_public_id' => $this->rowString($row, 'public_id'),
            'policy_code' => $this->rowString($row, 'policy_code'),
        ], request: $request);

        return $this->respondCreated($this->policyPayload($row), 'Islamic treatment policy created');
    }

    public function approvePolicy(Request $request, string $policyPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($policyPublicId, $actor, $request): object {
                $policy = DB::table('islamic_treatment_policies')->where('public_id', $policyPublicId)->lockForUpdate()->first();
                if (! is_object($policy)) {
                    throw new InvalidArgumentException('Islamic treatment policy not found.');
                }
                $this->assertPolicyShape((array) $policy, fromStoredPolicy: true);

                $this->approvalWorkflow->ensureWorkflow(
                    IslamicApprovalStateMachine::SUBJECT_TREATMENT_POLICY,
                    $policyPublicId,
                    $actor,
                    $request,
                );
                $workflow = $this->approvalWorkflow->workflowFor(IslamicApprovalStateMachine::SUBJECT_TREATMENT_POLICY, $policyPublicId);
                if (! is_object($workflow)) {
                    throw new InvalidArgumentException('Islamic treatment policy workflow is missing.');
                }
                $state = $this->rowString($workflow, 'current_state');
                if ($state === IslamicApprovalStateMachine::STATE_DRAFT) {
                    $this->approvalWorkflow->submit(
                        IslamicApprovalStateMachine::SUBJECT_TREATMENT_POLICY,
                        $policyPublicId,
                        $actor,
                        [],
                        $request,
                    );
                }
                $this->approvalWorkflow->approve(
                    IslamicApprovalStateMachine::SUBJECT_TREATMENT_POLICY,
                    $policyPublicId,
                    $actor,
                    [
                        'effective_from' => $this->rowString($policy, 'effective_from'),
                        'effective_to' => $this->nullableString(((array) $policy)['effective_to'] ?? null),
                    ],
                    $request,
                );

                DB::table('islamic_treatment_policies')->where('id', $this->rowInt($policy, 'id'))->update([
                    'status' => 'approved',
                    'approved_by_user_id' => $actor->id,
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('islamic_treatment_policies')->where('id', $this->rowInt($policy, 'id'))->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Islamic treatment policy could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_treatment_policy' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.treatment_policy.approved', actor: $actor, properties: [
            'policy_public_id' => $this->rowString($row, 'public_id'),
        ], request: $request);

        return $this->respondSuccess($this->policyPayload($row), 'Islamic treatment policy approved');
    }

    public function storeEvent(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'event_type' => ['required', Rule::in(['late_payment_fee', 'non_compliant_income_detected', 'purification_transfer', 'zakat_posting'])],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'policy_public_id' => ['sometimes', 'nullable', 'string', 'exists:islamic_treatment_policies,public_id'],
            'agency_public_id' => ['required', 'string', 'exists:agencies,public_id'],
            'product_family' => ['sometimes', 'nullable', 'string', 'max:64'],
            'product_public_id' => ['sometimes', 'nullable', 'string', 'exists:islamic_products,public_id'],
            'event_reference' => ['sometimes', 'nullable', 'string', 'max:128'],
            'occurred_on' => ['sometimes', 'nullable', 'date'],
            'source_subject_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'source_subject_public_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        try {
            $row = DB::transaction(function () use ($validated, $actor): object {
                $agencyId = $this->idByPublicId('agencies', $validated['agency_public_id']);
                if (! is_int($agencyId)) {
                    throw new InvalidArgumentException('Islamic treatment event agency is invalid.');
                }
                $occurredOn = is_string($validated['occurred_on'] ?? null) ? $validated['occurred_on'] : now()->toDateString();

                $policy = $this->routing->resolvePolicyForEvent(
                    eventType: (string) $validated['event_type'],
                    context: [
                        'agency_id' => $agencyId,
                        'product_family' => is_string($validated['product_family'] ?? null) ? $validated['product_family'] : null,
                        'product_public_id' => is_string($validated['product_public_id'] ?? null) ? $validated['product_public_id'] : null,
                        'as_of' => CarbonImmutable::parse($occurredOn),
                    ],
                    policyPublicId: is_string($validated['policy_public_id'] ?? null) ? $validated['policy_public_id'] : null,
                );

                $id = DB::table('islamic_treatment_events')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'policy_id' => $this->rowInt($policy, 'id'),
                    'event_type' => (string) $validated['event_type'],
                    'event_reference' => is_string($validated['event_reference'] ?? null) ? $validated['event_reference'] : null,
                    'agency_id' => $agencyId,
                    'currency' => is_string($validated['currency'] ?? null) ? strtoupper($validated['currency']) : 'XAF',
                    'amount_minor' => (int) $validated['amount_minor'],
                    'source_subject_type' => is_string($validated['source_subject_type'] ?? null) ? $validated['source_subject_type'] : null,
                    'source_subject_public_id' => is_string($validated['source_subject_public_id'] ?? null) ? $validated['source_subject_public_id'] : null,
                    'status' => 'draft',
                    'occurred_on' => $occurredOn,
                    'created_by_user_id' => $actor->id,
                    'metadata' => isset($validated['metadata']) ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('islamic_treatment_events')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Islamic treatment event could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_treatment_event' => [$exception->getMessage()]]);
        }

        return $this->respondCreated($this->eventPayload($row), 'Islamic treatment event created');
    }

    public function postEvent(Request $request, string $eventPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($eventPublicId, $actor): object {
                $event = DB::table('islamic_treatment_events')->where('public_id', $eventPublicId)->lockForUpdate()->first();
                if (! is_object($event)) {
                    throw new InvalidArgumentException('Islamic treatment event not found.');
                }
                if ($this->rowString($event, 'status') === 'posted') {
                    throw new InvalidArgumentException('Islamic treatment event is already posted.');
                }

                $policy = DB::table('islamic_treatment_policies')->where('id', $this->rowInt($event, 'policy_id'))->first(['public_id']);
                if (! is_object($policy)) {
                    throw new InvalidArgumentException('Islamic treatment policy for event is invalid.');
                }
                $agencyId = $this->rowNullableInt($event, 'agency_id');
                if (! is_int($agencyId)) {
                    throw new InvalidArgumentException('Islamic treatment event agency is invalid.');
                }
                $currency = $this->rowString($event, 'currency');
                $route = $this->routing->resolve(
                    eventType: $this->rowString($event, 'event_type'),
                    amountMinor: $this->rowInt($event, 'amount_minor'),
                    currency: $currency,
                    context: [
                        'agency_id' => $agencyId,
                        'as_of' => CarbonImmutable::parse($this->rowString($event, 'occurred_on')),
                        'actor' => $actor,
                    ],
                    policyPublicId: $this->rowString($policy, 'public_id'),
                );

                $accountingDay = $this->accountingDayGuard->assertCanRegister($actor, 'islamic.treatment', $agencyId);
                $businessDate = $accountingDay->business_date->toDateString();

                $journal = JournalEntry::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => 'IFTREAT-'.Str::upper(Str::random(10)),
                    'business_date' => $businessDate,
                    'accounting_day_id' => $accountingDay->id,
                    'agency_id' => $agencyId,
                    'source_module' => 'islamic_finance',
                    'source_type' => 'islamic_treatment_event',
                    'source_public_id' => $eventPublicId,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'description' => 'Islamic treatment event '.$this->rowString($event, 'event_type'),
                    'created_by_user_id' => $actor->id,
                    'idempotency_key' => 'islamic-treatment-event:'.$eventPublicId,
                ]);
                $amountMinor = $this->rowInt($event, 'amount_minor');
                $journal->lines()->createMany([
                    [
                        'public_id' => (string) Str::ulid(),
                        'agency_id' => $agencyId,
                        'ledger_account_id' => $route['debit_ledger_account_id'],
                        'debit_minor' => $amountMinor,
                        'credit_minor' => 0,
                        'currency' => $currency,
                        'line_memo' => 'Islamic treatment debit '.$route['treatment_bucket'],
                    ],
                    [
                        'public_id' => (string) Str::ulid(),
                        'agency_id' => $agencyId,
                        'ledger_account_id' => $route['credit_ledger_account_id'],
                        'debit_minor' => 0,
                        'credit_minor' => $amountMinor,
                        'currency' => $currency,
                        'line_memo' => 'Islamic treatment credit '.$route['treatment_bucket'],
                    ],
                ]);
                $this->postSystemJournal($journal, $actor);

                DB::table('islamic_treatment_events')->where('id', $this->rowInt($event, 'id'))->update([
                    'treatment_bucket' => $route['treatment_bucket'],
                    'operation_code' => $route['operation_code'],
                    'mapping_reference' => $route['mapping_reference'],
                    'journal_entry_id' => $journal->id,
                    'status' => 'posted',
                    'blocked_reason' => null,
                    'posted_at' => now(),
                    'updated_at' => now(),
                ]);

                $updated = DB::table('islamic_treatment_events')->where('id', $this->rowInt($event, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Islamic treatment event could not be reloaded.');
                }

                return $updated;
            });
        } catch (InvalidArgumentException $exception) {
            DB::table('islamic_treatment_events')
                ->where('public_id', $eventPublicId)
                ->update([
                    'status' => 'blocked',
                    'blocked_reason' => $exception->getMessage(),
                    'updated_at' => now(),
                ]);
            $this->securityAudit->record('islamic.treatment_event.blocked', actor: $actor, properties: [
                'event_public_id' => $eventPublicId,
                'reason' => $exception->getMessage(),
            ], request: $request);

            return $this->respondUnprocessable(errors: ['islamic_treatment_event' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.treatment_event.posted', actor: $actor, properties: [
            'event_public_id' => $this->rowString($row, 'public_id'),
            'operation_code' => $this->rowString($row, 'operation_code'),
            'bucket' => $this->rowString($row, 'treatment_bucket'),
        ], request: $request);
        if ($this->rowString($row, 'event_type') === 'purification_transfer') {
            $this->securityAudit->record('islamic.treatment_event.purified', actor: $actor, properties: [
                'event_public_id' => $this->rowString($row, 'public_id'),
            ], request: $request);
        }

        return $this->respondSuccess($this->eventPayload($row), 'Islamic treatment event posted');
    }

    public function reconciliationReport(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'policy_public_id' => ['sometimes', 'nullable', 'string', 'exists:islamic_treatment_policies,public_id'],
            'event_type' => ['sometimes', 'nullable', Rule::in(['late_payment_fee', 'non_compliant_income_detected', 'purification_transfer', 'zakat_posting'])],
        ])->validate();

        $eventQuery = DB::table('islamic_treatment_events as e')
            ->join('islamic_treatment_policies as p', 'p.id', '=', 'e.policy_id');
        if (is_string($validated['from'] ?? null)) {
            $eventQuery->where('e.occurred_on', '>=', $validated['from']);
        }
        if (is_string($validated['to'] ?? null)) {
            $eventQuery->where('e.occurred_on', '<=', $validated['to']);
        }
        if (is_string($validated['policy_public_id'] ?? null) && $validated['policy_public_id'] !== '') {
            $eventQuery->where('p.public_id', $validated['policy_public_id']);
        }
        if (is_string($validated['event_type'] ?? null) && $validated['event_type'] !== '') {
            $eventQuery->where('e.event_type', $validated['event_type']);
        }

        $sourceTotal = (int) $eventQuery->sum('e.amount_minor');
        $postedTotal = (int) (clone $eventQuery)->where('e.status', 'posted')->sum('e.amount_minor');
        $postedCount = (clone $eventQuery)->where('e.status', 'posted')->count();
        $blockedCount = (clone $eventQuery)->where('e.status', 'blocked')->count();

        $orphanJournals = DB::table('journal_entries as j')
            ->leftJoin('islamic_treatment_events as e', 'e.public_id', '=', 'j.source_public_id')
            ->where('j.source_module', 'islamic_finance')
            ->where('j.source_type', 'islamic_treatment_event')
            ->whereNull('e.id')
            ->count();

        $report = [
            'period' => [
                'from' => is_string($validated['from'] ?? null) ? $validated['from'] : null,
                'to' => is_string($validated['to'] ?? null) ? $validated['to'] : null,
            ],
            'source_total_minor' => $sourceTotal,
            'posted_total_minor' => $postedTotal,
            'posted_event_count' => $postedCount,
            'blocked_event_count' => $blockedCount,
            'unposted_total_minor' => $sourceTotal - $postedTotal,
            'orphan_journal_count' => $orphanJournals,
            'reconciled' => $sourceTotal === $postedTotal && $orphanJournals === 0,
        ];

        $this->securityAudit->record('islamic.treatment_report.generated', actor: $request->user(), properties: $report, request: $request);

        return $this->respondSuccess($report, 'Islamic treatment reconciliation report generated');
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function assertPolicyShape(array $candidate, bool $fromStoredPolicy = false): void
    {
        $scopeType = is_string($candidate['scope_type'] ?? null) ? $candidate['scope_type'] : null;
        if (! in_array($scopeType, ['institution', 'agency', 'product_family', 'product'], true)) {
            throw new InvalidArgumentException('Islamic treatment policy scope is invalid.');
        }
        if ($scopeType === 'agency' && ! is_numeric($candidate['agency_id'] ?? null) && ! is_string($candidate['agency_public_id'] ?? null)) {
            throw new InvalidArgumentException('Agency-scoped Islamic treatment policy requires agency scope.');
        }
        if (in_array($scopeType, ['product_family', 'product'], true) && ! is_string($candidate['scope_value'] ?? null)) {
            throw new InvalidArgumentException('Product-scoped Islamic treatment policy requires scope value.');
        }

        $charity = (bool) ($candidate['charity_treatment_enabled'] ?? false);
        $nonCompliant = (bool) ($candidate['non_compliant_income_treatment_enabled'] ?? false);
        $zakat = (bool) ($candidate['zakat_enabled'] ?? false);
        $purificationMode = is_string($candidate['purification_mode'] ?? null) ? $candidate['purification_mode'] : null;

        $required = $this->decodeJsonObject($candidate['required_operation_codes'] ?? null);
        if (! is_array($required)) {
            throw new InvalidArgumentException('Islamic treatment policy requires required_operation_codes map.');
        }
        if ($charity && ! is_string($required['late_payment_fee'] ?? null)) {
            throw new InvalidArgumentException('Islamic treatment policy must define late_payment_fee operation code when charity treatment is enabled.');
        }
        if ($nonCompliant && ! is_string($required['non_compliant_income_detected'] ?? null)) {
            throw new InvalidArgumentException('Islamic treatment policy must define non_compliant_income_detected operation code when non-compliant treatment is enabled.');
        }
        if ($zakat && ! is_string($required['zakat_posting'] ?? null)) {
            throw new InvalidArgumentException('Islamic treatment policy must define zakat_posting operation code when zakat is enabled.');
        }
        if (($charity || $nonCompliant || $fromStoredPolicy) && $purificationMode !== null && ! is_string($required['purification_transfer'] ?? null)) {
            throw new InvalidArgumentException('Islamic treatment policy with purification mode must define purification_transfer operation code.');
        }
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

    /**
     * @return array<string, mixed>
     */
    private function policyPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'policy_code' => $this->rowString($row, 'policy_code'),
            'version' => $this->rowInt($row, 'version'),
            'scope_type' => $this->rowString($row, 'scope_type'),
            'scope_value' => $this->nullableString(((array) $row)['scope_value'] ?? null),
            'agency_public_id' => $this->publicIdById('agencies', $this->rowNullableInt($row, 'agency_id')),
            'zakat_enabled' => (bool) (((array) $row)['zakat_enabled'] ?? false),
            'charity_treatment_enabled' => (bool) (((array) $row)['charity_treatment_enabled'] ?? false),
            'non_compliant_income_treatment_enabled' => (bool) (((array) $row)['non_compliant_income_treatment_enabled'] ?? false),
            'purification_mode' => $this->nullableString(((array) $row)['purification_mode'] ?? null),
            'required_operation_codes' => $this->decodeJsonObject(((array) $row)['required_operation_codes'] ?? null),
            'status' => $this->rowString($row, 'status'),
            'effective_from' => $this->rowString($row, 'effective_from'),
            'effective_to' => $this->nullableString(((array) $row)['effective_to'] ?? null),
            'approved_by_user_public_id' => $this->publicIdById('users', $this->rowNullableInt($row, 'approved_by_user_id')),
            'approved_at' => $this->nullableString(((array) $row)['approved_at'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(object $row): array
    {
        $policy = DB::table('islamic_treatment_policies')->where('id', $this->rowInt($row, 'policy_id'))->first(['public_id']);

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'policy_public_id' => is_object($policy) ? $this->rowString($policy, 'public_id') : null,
            'event_type' => $this->rowString($row, 'event_type'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
            'currency' => $this->rowString($row, 'currency'),
            'status' => $this->rowString($row, 'status'),
            'blocked_reason' => $this->nullableString(((array) $row)['blocked_reason'] ?? null),
            'treatment_bucket' => $this->nullableString(((array) $row)['treatment_bucket'] ?? null),
            'operation_code' => $this->nullableString(((array) $row)['operation_code'] ?? null),
            'mapping_reference' => $this->nullableString(((array) $row)['mapping_reference'] ?? null),
            'journal_entry_public_id' => $this->journalEntryPublicId($this->rowNullableInt($row, 'journal_entry_id')),
            'occurred_on' => $this->rowString($row, 'occurred_on'),
            'posted_at' => $this->nullableString(((array) $row)['posted_at'] ?? null),
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

    private function publicIdById(string $table, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $row = DB::table($table)->where('id', $id)->first(['public_id']);

        return is_object($row) && is_string($row->public_id) ? $row->public_id : null;
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
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return null;
    }

    private function journalEntryPublicId(?int $journalEntryId): ?string
    {
        if ($journalEntryId === null) {
            return null;
        }
        $row = DB::table('journal_entries')->where('id', $journalEntryId)->first(['public_id']);

        return is_object($row) && is_string($row->public_id) ? $row->public_id : null;
    }

    /**
     * @return array<string, mixed>|null
     */
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
