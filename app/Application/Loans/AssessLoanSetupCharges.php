<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Models\Loan;
use App\Models\LoanProduct;
use App\Support\Finance\FormulaPolicyKey;
use App\Support\Finance\FormulaPolicyRegistry;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class AssessLoanSetupCharges
{
    public function __construct(
        private readonly FormulaPolicyRegistry $formulaPolicyRegistry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Loan $loan): array
    {
        $this->formulaPolicyRegistry->requireApproved(FormulaPolicyKey::FeesTaxesInsurance);

        return DB::transaction(function () use ($loan): array {
            DB::table('loans')->where('id', $loan->id)->lockForUpdate()->first();

            $lockedLoan = Loan::query()
                ->with(['client', 'loanProduct'])
                ->whereKey($loan->id)
                ->firstOrFail();
            $product = $lockedLoan->loanProduct;

            if (! $product instanceof LoanProduct) {
                throw new InvalidArgumentException('Loan product is required before setup charge assessment.');
            }

            $existing = DB::table('loan_charge_assessments')
                ->where('loan_id', $lockedLoan->id)
                ->whereIn('charge_type', ['dossier_fee', 'dossier_fee_tax', 'guarantee_deposit'])
                ->orderBy('charge_type')
                ->get();
            if ($existing->isNotEmpty()) {
                return [
                    'loan' => $lockedLoan->refresh(),
                    'charges' => $existing->map(fn (object $row): array => $this->chargeRow($row))->values()->all(),
                    'insurance_amount_minor' => $lockedLoan->insurance_amount_minor,
                ];
            }

            $principal = $lockedLoan->approved_principal_minor ?? $lockedLoan->requested_amount_minor;
            $currency = $lockedLoan->currency;
            $rules = $this->arrayValue($product->getAttribute('rules'));
            $setupRules = $this->arrayValue($rules['setup_charges'] ?? null);

            $dossierFee = $this->dossierFee($principal, $product, $setupRules);
            $tax = $this->tax($principal, $dossierFee, $product, $setupRules);
            $guaranteeDeposit = $this->guaranteeDeposit($principal, $product);
            $insurance = $this->insurance($principal, $product);

            $charges = [];
            if ($dossierFee > 0) {
                $charges[] = $this->insertCharge($lockedLoan->id, 'dossier_fee', $principal, $this->rate($setupRules['dossier_fee_rate'] ?? null), $dossierFee, $currency, [
                    'refundable' => false,
                    'non_refundable_after' => 'setup_approval',
                    'stakeholder_section' => 6,
                ]);
            }

            if ($tax > 0) {
                $taxBase = $this->taxBase($principal, $dossierFee, $product, $setupRules);
                $charges[] = $this->insertCharge($lockedLoan->id, 'dossier_fee_tax', $taxBase, $this->rate($product->tax_rate), $tax, $currency, [
                    'tax_base' => $this->setupRuleString($setupRules, 'tax_base', 'principal_plus_interest'),
                    'stakeholder_section' => 7,
                ]);
            }

            if ($guaranteeDeposit > 0) {
                $charges[] = $this->insertCharge($lockedLoan->id, 'guarantee_deposit', $principal, $this->rate($product->guarantee_deposit_value), $guaranteeDeposit, $currency, [
                    'collection_method' => $this->setupRuleString($setupRules, 'guarantee_deposit_collection_method', 'cash'),
                    'released_at' => 'loan_closure_after_full_settlement',
                    'cannot_settle_unpaid_loans' => true,
                    'stakeholder_section' => 9,
                ]);
            }

            $lockedLoan->forceFill([
                'dossier_fees_minor' => $dossierFee,
                'dossier_fees_tax_minor' => $tax,
                'guarantee_deposit_amount_minor' => $guaranteeDeposit,
                'insurance_amount_minor' => $insurance,
            ])->save();

            return [
                'loan' => $lockedLoan->refresh(),
                'charges' => $charges,
                'insurance_amount_minor' => $insurance,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $setupRules
     */
    private function dossierFee(int $principal, LoanProduct $product, array $setupRules): int
    {
        $rate = $setupRules['dossier_fee_rate'] ?? null;
        if ($rate !== null && $rate !== '') {
            return $this->percentOf($principal, $rate);
        }

        return $product->fee_amount_minor ?? 0;
    }

    /**
     * @param  array<string, mixed>  $setupRules
     */
    private function tax(int $principal, int $dossierFee, LoanProduct $product, array $setupRules): int
    {
        if ($product->tax_rate === null) {
            return 0;
        }

        return $this->percentOf($this->taxBase($principal, $dossierFee, $product, $setupRules), $product->tax_rate);
    }

    /**
     * @param  array<string, mixed>  $setupRules
     */
    private function taxBase(int $principal, int $dossierFee, LoanProduct $product, array $setupRules): int
    {
        $base = match ($this->setupRuleString($setupRules, 'tax_base', 'principal_plus_interest')) {
            'principal_plus_interest', 'capital_plus_interest' => $principal + $this->totalFlatInterest($principal, $product),
            'principal' => $principal,
            'dossier_fee' => $dossierFee,
            default => throw new InvalidArgumentException('Unsupported setup tax base.'),
        };

        return $base;
    }

    private function totalFlatInterest(int $principal, LoanProduct $product): int
    {
        if ($product->interest_rate === null) {
            return 0;
        }

        return $this->percentOf($principal, $product->interest_rate);
    }

    private function guaranteeDeposit(int $principal, LoanProduct $product): int
    {
        if ($product->guarantee_deposit_type === 'percentage' && $product->guarantee_deposit_value !== null) {
            return $this->percentOf($principal, $product->guarantee_deposit_value);
        }

        if ($product->guarantee_deposit_type === 'fixed' && $product->guarantee_deposit_value !== null) {
            return $this->wholeMinor($product->guarantee_deposit_value);
        }

        return 0;
    }

    private function insurance(int $principal, LoanProduct $product): int
    {
        if ($product->insurance_rate === null) {
            return 0;
        }

        return $this->percentOf($principal, $product->insurance_rate);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function insertCharge(int $loanId, string $type, int $baseAmount, ?string $rate, int $amount, string $currency, array $metadata): array
    {
        $id = DB::table('loan_charge_assessments')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loanId,
            'charge_type' => $type,
            'base_amount_minor' => $baseAmount,
            'rate' => $rate,
            'assessed_amount_minor' => $amount,
            'currency' => $currency,
            'assessed_at' => now(),
            'due_on' => now()->toDateString(),
            'status' => 'assessed',
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('loan_charge_assessments')->where('id', $id)->first();
        if (! is_object($row)) {
            throw new RuntimeException('Inserted loan charge assessment could not be loaded.');
        }

        return $this->chargeRow($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function chargeRow(object $row): array
    {
        $data = (array) $row;

        return [
            'public_id' => $this->rowString($data, 'public_id'),
            'charge_type' => $this->rowString($data, 'charge_type'),
            'base_amount_minor' => $this->rowInt($data, 'base_amount_minor'),
            'rate' => $this->rowNullableString($data, 'rate'),
            'assessed_amount_minor' => $this->rowInt($data, 'assessed_amount_minor'),
            'currency' => $this->rowString($data, 'currency'),
            'status' => $this->rowString($data, 'status'),
            'metadata' => $this->rowJson($data, 'metadata'),
        ];
    }

    private function percentOf(int $baseMinor, mixed $rate): int
    {
        return BigDecimal::of((string) $baseMinor)
            ->multipliedBy(BigDecimal::of($this->numericString($rate)))
            ->dividedBy('100')
            ->toScale(0, RoundingMode::UNNECESSARY)
            ->toInt();
    }

    private function wholeMinor(mixed $amount): int
    {
        return BigDecimal::of($this->numericString($amount))
            ->toScale(0, RoundingMode::UNNECESSARY)
            ->toInt();
    }

    private function rate(mixed $rate): ?string
    {
        return $rate === null || $rate === '' ? null : $this->numericString($rate);
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $setupRules
     */
    private function setupRuleString(array $setupRules, string $key, string $default): string
    {
        $value = $setupRules[$key] ?? $default;

        if (! is_string($value) || $value === '') {
            return $default;
        }

        return $value;
    }

    private function numericString(mixed $value): string
    {
        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }

        throw new InvalidArgumentException('Expected a numeric setup-charge value.');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (! is_string($value) && ! is_int($value)) {
            throw new RuntimeException('Expected string database value for '.$key.'.');
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowNullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new RuntimeException('Expected nullable string database value for '.$key.'.');
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (! is_int($value) && ! is_string($value)) {
            throw new RuntimeException('Expected integer database value for '.$key.'.');
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<mixed, mixed>|null
     */
    private function rowJson(array $row, string $key): ?array
    {
        $value = $row[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }
}
