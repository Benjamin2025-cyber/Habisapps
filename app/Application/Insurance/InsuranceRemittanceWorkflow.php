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
use InvalidArgumentException;

final class InsuranceRemittanceWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly InsuranceAccountingService $insuranceAccounting,
    ) {}

    public function storeBatch(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'insurance_partner_public_id' => ['required', 'string', 'exists:insurance_partners,public_id'],
            'agency_public_id' => ['required', 'string', 'exists:agencies,public_id'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ])->validate();

        try {
            $batch = DB::transaction(function () use ($actor, $validated): object {
                $partner = DB::table('insurance_partners')->where('public_id', (string) $validated['insurance_partner_public_id'])->first(['id']);
                $agency = DB::table('agencies')->where('public_id', (string) $validated['agency_public_id'])->first(['id']);
                if (! is_object($partner) || ! is_object($agency)) {
                    throw new InvalidArgumentException('Insurance partner and agency are required.');
                }

                $partnerId = $this->rowInt($partner, 'id');
                $agencyId = $this->rowInt($agency, 'id');
                $currency = $this->stringValue($validated['currency'] ?? 'XAF', 'XAF');
                $periodFrom = (string) $validated['period_from'];
                $periodTo = (string) $validated['period_to'];

                $payments = DB::table('insurance_premium_payments')
                    ->join('insurance_premium_assessments', 'insurance_premium_assessments.id', '=', 'insurance_premium_payments.insurance_premium_assessment_id')
                    ->join('insurance_subscriptions', 'insurance_subscriptions.id', '=', 'insurance_premium_assessments.insurance_subscription_id')
                    ->join('insurance_products', 'insurance_products.id', '=', 'insurance_subscriptions.insurance_product_id')
                    ->join('insurance_premium_payment_splits', 'insurance_premium_payment_splits.insurance_premium_payment_id', '=', 'insurance_premium_payments.id')
                    ->where('insurance_products.insurance_partner_id', $partnerId)
                    ->where('insurance_subscriptions.agency_id', $agencyId)
                    ->where('insurance_premium_payments.currency', $currency)
                    ->where('insurance_premium_payments.status', 'posted')
                    ->whereNull('insurance_premium_payments.remitted_at')
                    ->whereBetween('insurance_premium_payments.paid_at', [$periodFrom.' 00:00:00', $periodTo.' 23:59:59'])
                    ->select([
                        'insurance_premium_payments.id',
                        'insurance_products.id as product_id',
                        'insurance_premium_payment_splits.split_type',
                        'insurance_premium_payment_splits.amount_minor',
                        'insurance_premium_payment_splits.ledger_account_id',
                    ])
                    ->get();

                if ($payments->isEmpty()) {
                    throw new InvalidArgumentException('No eligible unremitted premium payments found for this partner/agency/period.');
                }

                $batchId = DB::table('insurance_remittance_batches')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'insurance_partner_id' => $partnerId,
                    'agency_id' => $agencyId,
                    'period_from' => $periodFrom,
                    'period_to' => $periodTo,
                    'currency' => $currency,
                    'total_minor' => 0,
                    'status' => 'draft',
                    'created_by_user_id' => $actor->id,
                    'approved_by_user_id' => null,
                    'approved_at' => null,
                    'journal_entry_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $total = 0;
                foreach ($payments as $payment) {
                    $splitType = $this->rowString($payment, 'split_type');
                    $splitAmount = $this->rowInt($payment, 'amount_minor');
                    if ($splitAmount <= 0) {
                        continue;
                    }

                    DB::table('insurance_remittance_items')->insert([
                        'public_id' => (string) Str::ulid(),
                        'insurance_remittance_batch_id' => $batchId,
                        'insurance_premium_payment_id' => $this->rowInt($payment, 'id'),
                        'insurance_product_id' => $this->rowInt($payment, 'product_id'),
                        'split_type' => $splitType,
                        'amount_minor' => $splitAmount,
                        'ledger_account_id' => $this->rowInt($payment, 'ledger_account_id'),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    if ($splitType === 'insurer_payable') {
                        $total += $splitAmount;
                    }
                }

                DB::table('insurance_remittance_batches')->where('id', $batchId)->update([
                    'total_minor' => $total,
                    'updated_at' => now(),
                ]);

                $row = DB::table('insurance_remittance_batches')->where('id', $batchId)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Remittance batch could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['remittance_batch' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.remittance_batch.created', actor: $actor, properties: [
            'batch_public_id' => $this->rowString($batch, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->batchPayload($batch), 'Remittance batch created successfully');
    }

    public function approveBatch(Request $request, string $batchPublicId): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $batch = DB::transaction(function () use ($actor, $batchPublicId): object {
                $batch = DB::table('insurance_remittance_batches')->where('public_id', $batchPublicId)->lockForUpdate()->first();
                if (! is_object($batch)) {
                    throw new InvalidArgumentException('Remittance batch not found.');
                }
                if ($this->rowString($batch, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft remittance batches can be approved.');
                }
                if ($this->rowInt($batch, 'created_by_user_id') === $actor->id) {
                    throw new InvalidArgumentException('The creator cannot approve their own remittance batch.');
                }

                $items = DB::table('insurance_remittance_items')->where('insurance_remittance_batch_id', $this->rowInt($batch, 'id'))->get();
                foreach ($items as $item) {
                    $alreadyRemitted = DB::table('insurance_premium_payments')
                        ->where('id', $this->rowInt($item, 'insurance_premium_payment_id'))
                        ->whereNotNull('remitted_at')
                        ->exists();
                    if ($alreadyRemitted) {
                        throw new InvalidArgumentException('Batch contains already-remitted payments. Recreate the batch.');
                    }
                }

                $journalEntry = $this->insuranceAccounting->postRemittanceBatchJournal($batch, $items, $actor);
                foreach ($items as $item) {
                    if ($this->rowString($item, 'split_type') !== 'insurer_payable') {
                        continue;
                    }
                    DB::table('insurance_premium_payments')
                        ->where('id', $this->rowInt($item, 'insurance_premium_payment_id'))
                        ->update([
                            'remitted_at' => now(),
                            'remittance_batch_item_id' => $this->rowInt($item, 'id'),
                            'updated_at' => now(),
                        ]);
                }

                DB::table('insurance_remittance_batches')
                    ->where('id', $this->rowInt($batch, 'id'))
                    ->update([
                        'status' => 'posted',
                        'approved_by_user_id' => $actor->id,
                        'approved_at' => now(),
                        'journal_entry_id' => $journalEntry->id,
                        'updated_at' => now(),
                    ]);

                return DB::table('insurance_remittance_batches')->where('id', $this->rowInt($batch, 'id'))->first() ?? $batch;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['remittance_batch' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.remittance_batch.approved', actor: $actor, properties: [
            'batch_public_id' => $batchPublicId,
        ], request: $request);

        return $this->respondSuccess($this->batchPayload($batch), 'Remittance batch approved and posted');
    }

    private function actor(Request $request): ?User
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasPermissionTo('insurance.remittances.manage') ? $actor : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function batchPayload(object $batch): array
    {
        return [
            'public_id' => $this->rowString($batch, 'public_id'),
            'period_from' => $this->rowString($batch, 'period_from'),
            'period_to' => $this->rowString($batch, 'period_to'),
            'currency' => $this->rowString($batch, 'currency'),
            'total_minor' => $this->rowInt($batch, 'total_minor'),
            'status' => $this->rowString($batch, 'status'),
        ];
    }

    private function stringValue(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function rowString(object $row, string $key): string
    {
        return (string) (((array) $row)[$key] ?? '');
    }

    private function rowInt(object $row, string $key): int
    {
        return (int) (((array) $row)[$key] ?? 0);
    }
}

