<?php

declare(strict_types=1);

namespace App\Application\Insurance;

use App\Http\Controllers\BaseController;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class InsuranceSubscriptionWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.subscriptions.create');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'client_public_id' => ['required', 'string', 'exists:clients,public_id'],
            'agency_public_id' => ['required', 'string', 'exists:agencies,public_id'],
            'insurance_product_public_id' => ['required', 'string', 'exists:insurance_products,public_id'],
            'subscription_number' => ['sometimes', 'nullable', 'string', 'max:64', 'unique:insurance_subscriptions,subscription_number'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'coverage_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'suspended', 'cancelled', 'expired'])],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        try {
            $subscription = DB::transaction(function () use ($validated): object {
                $agency = DB::table('agencies')->where('public_id', (string) $validated['agency_public_id'])->first(['id']);
                $client = DB::table('clients')->where('public_id', (string) $validated['client_public_id'])->first(['id', 'agency_id']);
                $product = DB::table('insurance_products')
                    ->where('public_id', (string) $validated['insurance_product_public_id'])
                    ->where('status', 'active')
                    ->first(['id', 'currency', 'approval_status', 'new_business_enabled']);
                if (! is_object($agency) || ! is_object($client) || ! is_object($product)) {
                    throw new InvalidArgumentException('Client, agency, and active insurance product are required.');
                }
                if ($this->rowString($product, 'approval_status') !== 'approved') {
                    throw new InvalidArgumentException('Insurance product must pass readiness activation before subscription.');
                }
                if (! (bool) (((array) $product)['new_business_enabled'] ?? true)) {
                    throw new InvalidArgumentException('Insurance product is closed to new subscriptions.');
                }
                if ($this->rowInt($client, 'agency_id') !== $this->rowInt($agency, 'id')) {
                    throw new InvalidArgumentException('Insurance subscription client must belong to the selected agency.');
                }

                $currency = $this->stringValue($validated['currency'] ?? $this->rowString($product, 'currency'), 'XAF');
                if ($currency !== $this->rowString($product, 'currency')) {
                    throw new InvalidArgumentException('Insurance subscription currency must match the product currency.');
                }

                $id = DB::table('insurance_subscriptions')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'client_id' => $this->rowInt($client, 'id'),
                    'agency_id' => $this->rowInt($agency, 'id'),
                    'loan_id' => null,
                    'insurance_product_id' => $this->rowInt($product, 'id'),
                    'subscription_number' => $this->stringValue($validated['subscription_number'] ?? null, 'INS-SUB-'.Str::ulid()),
                    'starts_on' => $this->nullableString($validated['starts_on'] ?? null),
                    'ends_on' => $this->nullableString($validated['ends_on'] ?? null),
                    'coverage_amount_minor' => $this->nullableInt($validated['coverage_amount_minor'] ?? null),
                    'currency' => $currency,
                    'status' => $this->stringValue($validated['status'] ?? 'active', 'active'),
                    'metadata' => $this->jsonOrNull($validated['metadata'] ?? null),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $this->reloadSubscription($id, 'Insurance subscription could not be reloaded.');
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_subscription' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.subscription.created', actor: $actor, properties: [
            'subscription_public_id' => $this->rowString($subscription, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->subscriptionPayload($subscription), 'Insurance subscription created successfully');
    }

    public function activate(Request $request, string $subscriptionPublicId): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.subscriptions.manage');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'rule_version_public_id' => ['required', 'string', 'exists:insurance_product_rule_versions,public_id'],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($subscriptionPublicId, $validated): array {
                $subscription = DB::table('insurance_subscriptions')->where('public_id', $subscriptionPublicId)->lockForUpdate()->first();
                if (! is_object($subscription)) {
                    throw new InvalidArgumentException('Insurance subscription not found.');
                }
                if ($this->rowString($subscription, 'lifecycle_status') !== 'active') {
                    throw new InvalidArgumentException('Subscription cannot be activated in its current lifecycle status.');
                }
                if ($this->rowNullableInt($subscription, 'rule_version_id') !== null) {
                    throw new InvalidArgumentException('Subscription already has an active rule version assigned.');
                }

                $ruleVersion = DB::table('insurance_product_rule_versions')
                    ->where('public_id', (string) $validated['rule_version_public_id'])
                    ->first();
                if (! is_object($ruleVersion) || $this->rowString($ruleVersion, 'status') !== 'approved') {
                    throw new InvalidArgumentException('Rule version must be approved before use.');
                }
                if ($this->rowInt($subscription, 'insurance_product_id') !== $this->rowInt($ruleVersion, 'insurance_product_id')) {
                    throw new InvalidArgumentException('Rule version does not belong to the subscription product.');
                }

                DB::table('insurance_subscriptions')->where('id', $this->rowInt($subscription, 'id'))->update([
                    'rule_version_id' => $this->rowInt($ruleVersion, 'id'),
                    'updated_at' => now(),
                ]);

                $schedule = $this->createFirstScheduleIfNeeded($subscription, $ruleVersion);

                return [
                    'subscription' => $this->reloadSubscription($this->rowInt($subscription, 'id'), 'Subscription could not be reloaded.'),
                    'first_schedule' => $schedule,
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_subscription' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.subscription.activated', actor: $actor, properties: [
            'subscription_public_id' => $subscriptionPublicId,
        ], request: $request);

        $payload = ['subscription' => $this->subscriptionPayload($result['subscription'])];
        if (is_object($result['first_schedule'])) {
            $payload['first_schedule'] = $this->schedulePayload($result['first_schedule']);
        }

        return $this->respondSuccess($payload, 'Subscription activated successfully');
    }

    public function generatePremiumBatch(Request $request): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.premiums.manage');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'due_before' => ['required', 'date'],
        ])->validate();

        $generated = 0;
        $skipped = 0;
        $schedules = DB::table('insurance_premium_schedules')
            ->whereIn('status', ['scheduled', 'assessed'])
            ->where('due_on', '<=', (string) $validated['due_before'])
            ->get();

        foreach ($schedules as $schedule) {
            if ($this->scheduleAlreadyAssessed($schedule)) {
                $skipped++;

                continue;
            }

            $subscription = DB::table('insurance_subscriptions')->where('id', $this->rowInt($schedule, 'insurance_subscription_id'))->first();
            $ruleVersion = DB::table('insurance_product_rule_versions')->where('id', $this->rowInt($schedule, 'rule_version_id'))->first();
            if (! is_object($subscription) || $this->rowString($subscription, 'status') !== 'active' || ! is_object($ruleVersion)) {
                $skipped++;

                continue;
            }

            $this->assessSchedule($schedule, $subscription, $ruleVersion);
            $generated++;
        }

        return $this->respondSuccess(['generated' => $generated, 'skipped' => $skipped], 'Premium batch generation completed');
    }

    public function renew(Request $request, string $subscriptionPublicId): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.subscriptions.manage');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'starts_on' => ['required', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after:starts_on'],
            'coverage_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'rule_version_public_id' => ['required', 'string', 'exists:insurance_product_rule_versions,public_id'],
        ])->validate();

        try {
            $newSubscription = DB::transaction(function () use ($subscriptionPublicId, $validated): object {
                $old = DB::table('insurance_subscriptions')->where('public_id', $subscriptionPublicId)->lockForUpdate()->first();
                if (! is_object($old)) {
                    throw new InvalidArgumentException('Insurance subscription not found.');
                }

                $ruleVersion = DB::table('insurance_product_rule_versions')->where('public_id', (string) $validated['rule_version_public_id'])->first();
                if (! is_object($ruleVersion) || $this->rowString($ruleVersion, 'status') !== 'approved') {
                    throw new InvalidArgumentException('Renewal rule version must be approved.');
                }

                $id = DB::table('insurance_subscriptions')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'client_id' => $this->rowInt($old, 'client_id'),
                    'agency_id' => $this->rowInt($old, 'agency_id'),
                    'loan_id' => $this->rowNullableInt($old, 'loan_id'),
                    'insurance_product_id' => $this->rowInt($old, 'insurance_product_id'),
                    'subscription_number' => 'RNW-'.Str::ulid(),
                    'starts_on' => (string) $validated['starts_on'],
                    'ends_on' => $this->nullableString($validated['ends_on'] ?? null),
                    'coverage_amount_minor' => $this->nullableInt($validated['coverage_amount_minor'] ?? null) ?? $this->rowNullableInt($old, 'coverage_amount_minor'),
                    'currency' => $this->rowString($old, 'currency'),
                    'status' => 'active',
                    'lifecycle_status' => 'active',
                    'rule_version_id' => $this->rowInt($ruleVersion, 'id'),
                    'grace_period_ends_on' => null,
                    'cancelled_at' => null,
                    'metadata' => $this->rowNullableString($old, 'metadata'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $this->reloadSubscription($id, 'Renewed subscription could not be reloaded.');
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_subscription' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.subscription.renewed', actor: $actor, properties: [
            'prior_subscription_public_id' => $subscriptionPublicId,
            'new_subscription_public_id' => $this->rowString($newSubscription, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->subscriptionPayload($newSubscription), 'Subscription renewed successfully');
    }

    private function actor(Request $request, string $permission): ?User
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasPermissionTo($permission) ? $actor : null;
    }

    private function createFirstScheduleIfNeeded(object $subscription, object $ruleVersion): ?object
    {
        $frequency = $this->rowString($ruleVersion, 'frequency');
        $startsOn = $this->rowNullableString($subscription, 'starts_on');
        $periodKey = 'sub_'.$this->rowInt($subscription, 'id').'_p1';
        if ($startsOn === null || $frequency === 'one_time' || DB::table('insurance_premium_schedules')->where('idempotency_key', $periodKey)->exists()) {
            return null;
        }

        $id = DB::table('insurance_premium_schedules')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'insurance_subscription_id' => $this->rowInt($subscription, 'id'),
            'rule_version_id' => $this->rowInt($ruleVersion, 'id'),
            'period_number' => 1,
            'due_on' => $this->computeNextDueDate($startsOn, $frequency, 0),
            'idempotency_key' => $periodKey,
            'insurance_premium_assessment_id' => null,
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('insurance_premium_schedules')->where('id', $id)->first();
    }

    private function scheduleAlreadyAssessed(object $schedule): bool
    {
        return $this->rowNullableInt($schedule, 'insurance_premium_assessment_id') !== null
            || DB::table('insurance_premium_assessments')->where('period_key', $this->rowString($schedule, 'idempotency_key'))->exists();
    }

    private function assessSchedule(object $schedule, object $subscription, object $ruleVersion): void
    {
        $premiumMinor = $this->calculatePremiumMinor($ruleVersion, $subscription);
        DB::transaction(function () use ($schedule, $subscription, $ruleVersion, $premiumMinor): void {
            $assessmentId = DB::table('insurance_premium_assessments')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'insurance_subscription_id' => $this->rowInt($subscription, 'id'),
                'loan_id' => null,
                'rule_version_id' => $this->rowInt($ruleVersion, 'id'),
                'period_key' => $this->rowString($schedule, 'idempotency_key'),
                'base_amount_minor' => $this->rowNullableInt($subscription, 'coverage_amount_minor'),
                'rate' => $this->rowNullableString($ruleVersion, 'rate'),
                'premium_amount_minor' => $premiumMinor,
                'currency' => $this->rowString($subscription, 'currency'),
                'due_on' => $this->rowString($schedule, 'due_on'),
                'assessed_at' => now(),
                'status' => 'assessed',
                'journal_entry_id' => null,
                'metadata' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('insurance_premium_schedules')->where('id', $this->rowInt($schedule, 'id'))->update([
                'insurance_premium_assessment_id' => $assessmentId,
                'status' => 'assessed',
                'updated_at' => now(),
            ]);
        });
    }

    private function calculatePremiumMinor(object $ruleVersion, object $subscription): int
    {
        $fixed = $this->rowNullableInt($ruleVersion, 'fixed_premium_minor');
        if ($fixed !== null) {
            return $fixed;
        }

        $base = $this->rowNullableInt($subscription, 'coverage_amount_minor') ?? 0;
        $rate = (float) ($this->rowNullableString($ruleVersion, 'rate') ?? '0');

        return max(0, (int) round($base * ($rate / 100)));
    }

    private function computeNextDueDate(string $startsOn, string $frequency, int $offset): string
    {
        $date = new \DateTimeImmutable($startsOn);

        return match ($frequency) {
            'monthly' => $date->modify("+{$offset} months")->format('Y-m-d'),
            'quarterly' => $date->modify('+'.($offset * 3).' months')->format('Y-m-d'),
            'annual' => $date->modify("+{$offset} years")->format('Y-m-d'),
            default => $startsOn,
        };
    }

    private function reloadSubscription(int $id, string $message): object
    {
        $row = DB::table('insurance_subscriptions')->where('id', $id)->first();
        if (! is_object($row)) {
            throw new InvalidArgumentException($message);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
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

    /**
     * @return array<string, mixed>
     */
    private function schedulePayload(object $schedule): array
    {
        return [
            'public_id' => $this->rowString($schedule, 'public_id'),
            'period_number' => $this->rowInt($schedule, 'period_number'),
            'due_on' => $this->rowString($schedule, 'due_on'),
            'status' => $this->rowString($schedule, 'status'),
        ];
    }

    private function jsonOrNull(mixed $value): ?string
    {
        return is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : null;
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
