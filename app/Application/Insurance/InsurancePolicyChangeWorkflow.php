<?php

declare(strict_types=1);

namespace App\Application\Insurance;

use App\Http\Controllers\BaseController;
use App\Models\CustomerAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class InsurancePolicyChangeWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly InsuranceAccountingService $insuranceAccounting,
    ) {}

    public function storeEndorsement(Request $request, string $subscriptionPublicId): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.subscriptions.manage');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'endorsement_type' => ['required', 'string', Rule::in(['coverage_amount', 'beneficiary', 'dates', 'other'])],
            'before_values' => ['required', 'array'],
            'after_values' => ['required', 'array'],
            'effective_on' => ['required', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        try {
            $endorsement = DB::transaction(function () use ($actor, $subscriptionPublicId, $validated): object {
                $subscription = DB::table('insurance_subscriptions')
                    ->where('public_id', $subscriptionPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($subscription)) {
                    throw new InvalidArgumentException('Insurance subscription not found.');
                }
                if ($this->rowString($subscription, 'status') !== 'active') {
                    throw new InvalidArgumentException('Only active subscriptions can be endorsed.');
                }

                $id = DB::table('insurance_endorsements')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'insurance_subscription_id' => $this->rowInt($subscription, 'id'),
                    'endorsement_type' => (string) $validated['endorsement_type'],
                    'before_values' => json_encode($validated['before_values'], JSON_THROW_ON_ERROR),
                    'after_values' => json_encode($validated['after_values'], JSON_THROW_ON_ERROR),
                    'effective_on' => (string) $validated['effective_on'],
                    'reason' => $this->nullableString($validated['reason'] ?? null),
                    'status' => 'pending',
                    'requested_by_user_id' => $actor->id,
                    'reviewed_by_user_id' => null,
                    'reviewed_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('insurance_endorsements')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Endorsement could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['endorsement' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.endorsement.created', actor: $actor, properties: [
            'subscription_public_id' => $subscriptionPublicId,
            'endorsement_public_id' => $this->rowString($endorsement, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->endorsementPayload($endorsement), 'Endorsement request created successfully');
    }

    public function approveEndorsement(Request $request, string $endorsementPublicId): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.subscriptions.manage');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'review_decision' => ['required', Rule::in(['approve', 'reject'])],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($actor, $endorsementPublicId, $validated): array {
                $endorsement = DB::table('insurance_endorsements')
                    ->where('public_id', $endorsementPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($endorsement)) {
                    throw new InvalidArgumentException('Endorsement not found.');
                }
                if ($this->rowString($endorsement, 'status') !== 'pending') {
                    throw new InvalidArgumentException('Only pending endorsements can be reviewed.');
                }
                if ($this->rowInt($endorsement, 'requested_by_user_id') === $actor->id) {
                    throw new InvalidArgumentException('The requester cannot review their own endorsement.');
                }

                $newStatus = (string) $validated['review_decision'] === 'approve' ? 'approved' : 'rejected';
                DB::table('insurance_endorsements')
                    ->where('id', $this->rowInt($endorsement, 'id'))
                    ->update([
                        'status' => $newStatus,
                        'reviewed_by_user_id' => $actor->id,
                        'reviewed_at' => now(),
                        'updated_at' => now(),
                    ]);

                if ($newStatus === 'approved') {
                    $afterValues = json_decode($this->rowString($endorsement, 'after_values'), true);
                    if (is_array($afterValues)) {
                        $allowedCols = ['coverage_amount_minor', 'ends_on'];
                        $updates = array_intersect_key($afterValues, array_flip($allowedCols));
                        if ($updates !== []) {
                            $updates['updated_at'] = now()->toDateTimeString();
                            DB::table('insurance_subscriptions')
                                ->where('id', $this->rowInt($endorsement, 'insurance_subscription_id'))
                                ->update($updates);
                        }
                    }
                }

                $updated = DB::table('insurance_endorsements')
                    ->where('id', $this->rowInt($endorsement, 'id'))
                    ->first();

                return ['endorsement' => $updated ?? $endorsement];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['endorsement' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.endorsement.reviewed', actor: $actor, properties: [
            'endorsement_public_id' => $endorsementPublicId,
            'decision' => (string) $validated['review_decision'],
        ], request: $request);

        return $this->respondSuccess($this->endorsementPayload($result['endorsement']), 'Endorsement reviewed successfully');
    }

    public function cancelSubscription(Request $request, string $subscriptionPublicId): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.subscriptions.manage');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'effective_on' => ['required', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'refund_treatment' => ['sometimes', Rule::in(['none', 'pro_rata', 'full'])],
            'refund_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'refund_customer_account_public_id' => ['required_with:refund_amount_minor', 'nullable', 'string', 'exists:customer_accounts,public_id'],
        ])->validate();

        try {
            $cancellation = DB::transaction(function () use ($actor, $subscriptionPublicId, $validated): object {
                $subscription = DB::table('insurance_subscriptions')
                    ->where('public_id', $subscriptionPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($subscription)) {
                    throw new InvalidArgumentException('Insurance subscription not found.');
                }
                if ($this->rowString($subscription, 'status') !== 'active') {
                    throw new InvalidArgumentException('Only active subscriptions can be cancelled.');
                }
                $refundTreatment = $this->stringValue($validated['refund_treatment'] ?? 'none', 'none');
                $refundAmountMinor = $this->nullableInt($validated['refund_amount_minor'] ?? null);
                $refundCustomerAccountId = null;
                if ($refundTreatment !== 'none' && $refundAmountMinor !== null) {
                    $refundAccount = DB::table('customer_accounts')
                        ->where('public_id', (string) $validated['refund_customer_account_public_id'])
                        ->where('status', CustomerAccount::STATUS_ACTIVE)
                        ->first(['id', 'client_id', 'agency_id', 'currency']);
                    if (! is_object($refundAccount)) {
                        throw new InvalidArgumentException('Refund customer account must be active.');
                    }
                    if ($this->rowInt($refundAccount, 'client_id') !== $this->rowInt($subscription, 'client_id')
                        || $this->rowInt($refundAccount, 'agency_id') !== $this->rowInt($subscription, 'agency_id')
                        || $this->rowString($refundAccount, 'currency') !== $this->rowString($subscription, 'currency')) {
                        throw new InvalidArgumentException('Refund account must belong to the subscription client, agency, and currency.');
                    }
                    $refundCustomerAccountId = $this->rowInt($refundAccount, 'id');
                }

                $id = DB::table('insurance_cancellations')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'insurance_subscription_id' => $this->rowInt($subscription, 'id'),
                    'effective_on' => (string) $validated['effective_on'],
                    'reason' => $this->nullableString($validated['reason'] ?? null),
                    'refund_treatment' => $refundTreatment,
                    'refund_amount_minor' => $refundAmountMinor,
                    'refund_customer_account_id' => $refundCustomerAccountId,
                    'refund_journal_entry_id' => null,
                    'status' => 'pending',
                    'requested_by_user_id' => $actor->id,
                    'approved_by_user_id' => null,
                    'approved_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('insurance_cancellations')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Cancellation request could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['cancellation' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.subscription.cancellation_requested', actor: $actor, properties: [
            'subscription_public_id' => $subscriptionPublicId,
            'cancellation_public_id' => $this->rowString($cancellation, 'public_id'),
        ], request: $request);

        return $this->respondCreated([
            'public_id' => $this->rowString($cancellation, 'public_id'),
            'subscription_public_id' => $subscriptionPublicId,
            'effective_on' => $this->rowString($cancellation, 'effective_on'),
            'refund_treatment' => $this->rowString($cancellation, 'refund_treatment'),
            'status' => $this->rowString($cancellation, 'status'),
        ], 'Cancellation request created; awaiting approval');
    }

    public function reviewCancellation(Request $request, string $cancellationPublicId): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.subscriptions.manage');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'review_decision' => ['required', Rule::in(['approve', 'reject'])],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($actor, $cancellationPublicId, $validated): array {
                $cancellation = DB::table('insurance_cancellations')
                    ->where('public_id', $cancellationPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($cancellation)) {
                    throw new InvalidArgumentException('Cancellation request not found.');
                }
                if ($this->rowString($cancellation, 'status') !== 'pending') {
                    throw new InvalidArgumentException('Only pending cancellation requests can be reviewed.');
                }
                if ($this->rowInt($cancellation, 'requested_by_user_id') === $actor->id) {
                    throw new InvalidArgumentException('The requester cannot review their own cancellation request.');
                }

                $subscription = DB::table('insurance_subscriptions')
                    ->where('id', $this->rowInt($cancellation, 'insurance_subscription_id'))
                    ->lockForUpdate()
                    ->first();
                if (! is_object($subscription)) {
                    throw new InvalidArgumentException('Insurance subscription not found.');
                }

                $newStatus = ((string) $validated['review_decision']) === 'approve' ? 'approved' : 'rejected';
                DB::table('insurance_cancellations')
                    ->where('id', $this->rowInt($cancellation, 'id'))
                    ->update([
                        'status' => $newStatus,
                        'approved_by_user_id' => $actor->id,
                        'approved_at' => now(),
                        'updated_at' => now(),
                    ]);

                if ($newStatus === 'approved') {
                    $effectiveOn = $this->rowString($cancellation, 'effective_on');
                    $effectiveNow = $effectiveOn <= now()->toDateString();
                    DB::table('insurance_subscriptions')
                        ->where('id', $this->rowInt($subscription, 'id'))
                        ->update([
                            'status' => $effectiveNow ? 'cancelled' : 'active',
                            'lifecycle_status' => $effectiveNow ? 'cancelled' : 'cancellation_approved',
                            'cancelled_at' => $effectiveNow ? now() : null,
                            'updated_at' => now(),
                        ]);

                    DB::table('insurance_premium_schedules')
                        ->where('insurance_subscription_id', $this->rowInt($subscription, 'id'))
                        ->where('status', 'scheduled')
                        ->whereDate('due_on', '>=', $effectiveOn)
                        ->update(['status' => 'cancelled', 'updated_at' => now()]);

                    $refundJournalEntry = $this->insuranceAccounting->postCancellationRefundIfRequired($cancellation, $subscription, $actor);
                    if ($refundJournalEntry instanceof JournalEntry) {
                        DB::table('insurance_cancellations')
                            ->where('id', $this->rowInt($cancellation, 'id'))
                            ->update([
                                'refund_journal_entry_id' => $refundJournalEntry->id,
                                'updated_at' => now(),
                            ]);
                    }
                }

                $updatedCancellation = DB::table('insurance_cancellations')
                    ->where('id', $this->rowInt($cancellation, 'id'))
                    ->first();
                $updatedSubscription = DB::table('insurance_subscriptions')
                    ->where('id', $this->rowInt($subscription, 'id'))
                    ->first();
                if (! is_object($updatedCancellation) || ! is_object($updatedSubscription)) {
                    throw new InvalidArgumentException('Cancellation review could not be reloaded.');
                }

                return ['cancellation' => $updatedCancellation, 'subscription' => $updatedSubscription];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['cancellation' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.subscription.cancellation_reviewed', actor: $actor, properties: [
            'cancellation_public_id' => $cancellationPublicId,
            'status' => $this->rowString($result['cancellation'], 'status'),
        ], request: $request);

        return $this->respondSuccess([
            'cancellation' => [
                'public_id' => $this->rowString($result['cancellation'], 'public_id'),
                'effective_on' => $this->rowString($result['cancellation'], 'effective_on'),
                'refund_treatment' => $this->rowString($result['cancellation'], 'refund_treatment'),
                'refund_amount_minor' => $this->rowNullableInt($result['cancellation'], 'refund_amount_minor'),
                'refund_journal_entry_public_id' => $this->journalEntryPublicId($this->rowNullableInt($result['cancellation'], 'refund_journal_entry_id')),
                'status' => $this->rowString($result['cancellation'], 'status'),
            ],
            'subscription' => $this->subscriptionPayload($result['subscription']),
        ], 'Cancellation request reviewed successfully');
    }

    /**
     * @return array<string,mixed>
     */
    private function endorsementPayload(object $endorsement): array
    {
        return [
            'public_id' => $this->rowString($endorsement, 'public_id'),
            'endorsement_type' => $this->rowString($endorsement, 'endorsement_type'),
            'effective_on' => $this->rowString($endorsement, 'effective_on'),
            'status' => $this->rowString($endorsement, 'status'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function subscriptionPayload(object $subscription): array
    {
        return [
            'public_id' => $this->rowString($subscription, 'public_id'),
            'subscription_number' => $this->rowString($subscription, 'subscription_number'),
            'starts_on' => $this->rowNullableString($subscription, 'starts_on'),
            'ends_on' => $this->rowNullableString($subscription, 'ends_on'),
            'coverage_amount_minor' => $this->rowNullableInt($subscription, 'coverage_amount_minor'),
            'currency' => $this->rowString($subscription, 'currency'),
            'status' => $this->rowString($subscription, 'status'),
        ];
    }

    private function journalEntryPublicId(?int $journalEntryId): ?string
    {
        if ($journalEntryId === null) {
            return null;
        }

        $publicId = DB::table('journal_entries')->where('id', $journalEntryId)->value('public_id');

        return is_string($publicId) ? $publicId : null;
    }

    private function actor(Request $request, string $permission): ?User
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasPermissionTo($permission) ? $actor : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function stringValue(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function rowString(object $row, string $key): string
    {
        return (string) (((array) $row)[$key] ?? '');
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function rowInt(object $row, string $key): int
    {
        return (int) (((array) $row)[$key] ?? 0);
    }

    private function rowNullableInt(object $row, string $key): ?int
    {
        $value = ((array) $row)[$key] ?? null;

        return $value === null ? null : (int) $value;
    }
}
