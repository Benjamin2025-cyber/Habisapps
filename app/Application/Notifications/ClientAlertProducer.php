<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Models\Client;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class ClientAlertProducer
{
    public const string TEMPLATE_LOAN_DUE = 'loan_due_alert';

    public const string TEMPLATE_LOAN_OVERDUE = 'loan_overdue_alert';

    public const string TEMPLATE_INSURANCE_PREMIUM_DUE = 'insurance_premium_due_alert';

    public const string TEMPLATE_CLAIM_DECISION = 'insurance_claim_decision_alert';

    public function __construct(
        private readonly NotificationOutbox $outbox,
        private readonly NotificationConsentManager $consents,
    ) {}

    public function produceLoanDueAlerts(CarbonInterface $businessDate): int
    {
        $rows = DB::table('loan_schedule_lines as line')
            ->join('loan_schedule_snapshots as snapshot', 'snapshot.id', '=', 'line.loan_schedule_snapshot_id')
            ->join('loans', 'loans.id', '=', 'snapshot.loan_id')
            ->join('clients', 'clients.id', '=', 'loans.client_id')
            ->where('line.due_date', $businessDate->toDateString())
            ->where('snapshot.status', 'active')
            ->whereIn('loans.status', ['active', 'disbursed', 'rescheduled'])
            ->whereNotIn('line.status', ['paid', 'cancelled'])
            ->whereNotNull('clients.phone_number')
            ->select([
                'line.id as line_id',
                'line.installment_number as installment_number',
                'line.due_date as due_date',
                'line.principal_minor as principal_minor',
                'line.interest_minor as interest_minor',
                'line.fees_minor as fees_minor',
                'line.insurance_minor as insurance_minor',
                'line.tax_minor as tax_minor',
                'line.currency as currency',
                'loans.id as loan_id',
                'loans.loan_number as loan_number',
                'loans.client_id as client_id',
                'clients.first_name as first_name',
                'clients.last_name as last_name',
                'clients.phone_number as phone_number',
            ])
            ->orderBy('line.id')
            ->get();

        $created = 0;
        foreach ($rows as $row) {
            $clientId = $this->rowInt($row, 'client_id');
            if (! $this->consents->hasOptedIn($clientId, 'sms', 'loan_due')) {
                continue;
            }

            $amount = $this->rowInt($row, 'principal_minor')
                + $this->rowInt($row, 'interest_minor')
                + $this->rowInt($row, 'fees_minor')
                + $this->rowInt($row, 'insurance_minor')
                + $this->rowInt($row, 'tax_minor');
            if ($amount <= 0) {
                continue;
            }

            $idempotencyKey = sprintf(
                'loan_due:%d:%d:%s',
                $this->rowInt($row, 'loan_id'),
                $this->rowInt($row, 'installment_number'),
                $this->rowString($row, 'due_date'),
            );

            $enqueued = $this->safeEnqueue(
                templateCode: self::TEMPLATE_LOAN_DUE,
                category: 'loan_due',
                phoneNumber: $this->rowString($row, 'phone_number'),
                idempotencyKey: $idempotencyKey,
                variables: [
                    'client_name' => trim($this->rowString($row, 'first_name').' '.$this->rowString($row, 'last_name')),
                    'amount' => (string) $amount,
                    'due_date' => $this->rowString($row, 'due_date'),
                    'loan_number' => $this->rowString($row, 'loan_number'),
                ],
                recipientId: $clientId,
                metadata: [
                    'loan_id' => $this->rowInt($row, 'loan_id'),
                    'installment_number' => $this->rowInt($row, 'installment_number'),
                    'currency' => $this->rowString($row, 'currency'),
                ],
            );
            if ($enqueued) {
                $created++;
            }
        }

        return $created;
    }

    public function produceLoanOverdueAlerts(CarbonInterface $businessDate): int
    {
        $rows = DB::table('loan_arrears as arrear')
            ->join('loans', 'loans.id', '=', 'arrear.loan_id')
            ->join('clients', 'clients.id', '=', 'loans.client_id')
            ->where('arrear.status', 'open')
            ->whereIn('loans.status', ['active', 'disbursed', 'rescheduled'])
            ->where('arrear.unpaid_minor', '>', 0)
            ->where('arrear.due_on', '<', $businessDate->toDateString())
            ->whereNotNull('clients.phone_number')
            ->select([
                'arrear.public_id as arrear_public_id',
                'arrear.due_on as due_on',
                'arrear.unpaid_minor as unpaid_minor',
                'arrear.currency as currency',
                'loans.loan_number as loan_number',
                'loans.client_id as client_id',
                'clients.first_name as first_name',
                'clients.last_name as last_name',
                'clients.phone_number as phone_number',
            ])
            ->orderBy('arrear.id')
            ->get();

        $created = 0;
        foreach ($rows as $row) {
            $clientId = $this->rowInt($row, 'client_id');
            if (! $this->consents->hasOptedIn($clientId, 'sms', 'loan_overdue')) {
                continue;
            }

            $dueOn = Carbon::parse($this->rowString($row, 'due_on'))->startOfDay();
            $daysOverdue = max(1, (int) $dueOn->diffInDays($businessDate->copy()->startOfDay()));

            $idempotencyKey = sprintf(
                'loan_overdue:%s:%s',
                $this->rowString($row, 'arrear_public_id'),
                $businessDate->toDateString(),
            );

            $enqueued = $this->safeEnqueue(
                templateCode: self::TEMPLATE_LOAN_OVERDUE,
                category: 'loan_overdue',
                phoneNumber: $this->rowString($row, 'phone_number'),
                idempotencyKey: $idempotencyKey,
                variables: [
                    'client_name' => trim($this->rowString($row, 'first_name').' '.$this->rowString($row, 'last_name')),
                    'amount' => (string) $this->rowInt($row, 'unpaid_minor'),
                    'days_overdue' => (string) $daysOverdue,
                    'loan_number' => $this->rowString($row, 'loan_number'),
                ],
                recipientId: $clientId,
                metadata: [
                    'arrear_public_id' => $this->rowString($row, 'arrear_public_id'),
                    'currency' => $this->rowString($row, 'currency'),
                ],
            );
            if ($enqueued) {
                $created++;
            }
        }

        return $created;
    }

    public function produceInsurancePremiumDueAlerts(CarbonInterface $businessDate): int
    {
        $rows = DB::table('insurance_premium_assessments as assess')
            ->join('insurance_subscriptions as sub', 'sub.id', '=', 'assess.insurance_subscription_id')
            ->join('clients', 'clients.id', '=', 'sub.client_id')
            ->where('assess.due_on', $businessDate->toDateString())
            ->where('assess.status', 'assessed')
            ->whereNotNull('clients.phone_number')
            ->select([
                'assess.public_id as assessment_public_id',
                'assess.premium_amount_minor as amount',
                'assess.due_on as due_on',
                'assess.currency as currency',
                'sub.subscription_number as subscription_number',
                'sub.client_id as client_id',
                'clients.first_name as first_name',
                'clients.last_name as last_name',
                'clients.phone_number as phone_number',
            ])
            ->orderBy('assess.id')
            ->get();

        $created = 0;
        foreach ($rows as $row) {
            $clientId = $this->rowInt($row, 'client_id');
            if (! $this->consents->hasOptedIn($clientId, 'sms', 'insurance_premium_due')) {
                continue;
            }

            $idempotencyKey = sprintf(
                'insurance_premium_due:%s:%s',
                $this->rowString($row, 'assessment_public_id'),
                $this->rowString($row, 'due_on'),
            );

            $enqueued = $this->safeEnqueue(
                templateCode: self::TEMPLATE_INSURANCE_PREMIUM_DUE,
                category: 'insurance_premium_due',
                phoneNumber: $this->rowString($row, 'phone_number'),
                idempotencyKey: $idempotencyKey,
                variables: [
                    'client_name' => trim($this->rowString($row, 'first_name').' '.$this->rowString($row, 'last_name')),
                    'amount' => (string) $this->rowInt($row, 'amount'),
                    'due_date' => $this->rowString($row, 'due_on'),
                    'subscription_number' => $this->rowString($row, 'subscription_number'),
                ],
                recipientId: $clientId,
                metadata: [
                    'assessment_public_id' => $this->rowString($row, 'assessment_public_id'),
                    'currency' => $this->rowString($row, 'currency'),
                ],
            );
            if ($enqueued) {
                $created++;
            }
        }

        return $created;
    }

    public function produceInsuranceClaimDecisionAlerts(CarbonInterface $businessDate): int
    {
        $rows = DB::table('insurance_claims as claim')
            ->join('insurance_subscriptions as sub', 'sub.id', '=', 'claim.insurance_subscription_id')
            ->join('clients', 'clients.id', '=', 'sub.client_id')
            ->whereIn('claim.status', ['approved', 'rejected', 'settled'])
            ->whereNotNull('clients.phone_number')
            ->select([
                'claim.public_id as claim_public_id',
                'claim.claim_number as claim_number',
                'claim.status as status',
                'claim.updated_at as updated_at',
                'sub.client_id as client_id',
                'clients.first_name as first_name',
                'clients.last_name as last_name',
                'clients.phone_number as phone_number',
            ])
            ->orderBy('claim.id')
            ->get();

        $created = 0;
        foreach ($rows as $row) {
            $clientId = $this->rowInt($row, 'client_id');
            if (! $this->consents->hasOptedIn($clientId, 'sms', 'insurance_claim_decision')) {
                continue;
            }

            $idempotencyKey = sprintf(
                'insurance_claim_decision:%s:%s',
                $this->rowString($row, 'claim_public_id'),
                $this->rowString($row, 'status'),
            );

            $enqueued = $this->safeEnqueue(
                templateCode: self::TEMPLATE_CLAIM_DECISION,
                category: 'insurance_claim_decision',
                phoneNumber: $this->rowString($row, 'phone_number'),
                idempotencyKey: $idempotencyKey,
                variables: [
                    'client_name' => trim($this->rowString($row, 'first_name').' '.$this->rowString($row, 'last_name')),
                    'claim_number' => $this->rowString($row, 'claim_number'),
                    'decision' => $this->rowString($row, 'status'),
                ],
                recipientId: $clientId,
                metadata: [
                    'claim_public_id' => $this->rowString($row, 'claim_public_id'),
                    'event_business_date' => $businessDate->toDateString(),
                ],
            );
            if ($enqueued) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $metadata
     */
    private function safeEnqueue(
        string $templateCode,
        string $category,
        string $phoneNumber,
        string $idempotencyKey,
        array $variables,
        int $recipientId,
        array $metadata,
    ): bool {
        $existsBefore = DB::table('notification_deliveries')
            ->where('idempotency_key', $idempotencyKey)
            ->exists();
        if ($existsBefore) {
            return false;
        }

        try {
            $this->outbox->enqueue(
                templateCode: $templateCode,
                category: $category,
                channel: 'sms',
                destination: $phoneNumber,
                idempotencyKey: $idempotencyKey,
                variables: $variables,
                recipientType: Client::class,
                recipientId: $recipientId,
                metadata: $metadata,
            );

            return true;
        } catch (InvalidArgumentException) {
            return false;
        } catch (Throwable) {
            return false;
        }
    }

    private function rowInt(object $row, string $key): int
    {
        $value = ((array) $row)[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }
}
