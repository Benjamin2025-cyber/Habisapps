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
    /**
     * Setup charges are settled before money leaves the institution, so
     * assessment belongs to the pre-disbursement statuses only. Without this
     * gate the action is callable on a live loan, and on a product whose
     * components were switched from financed back to upfront it would assess
     * charges the borrower is already paying inside the schedule.
     *
     * @var array<int, string>
     */
    private const array ASSESSABLE_STATUSES = [
        Loan::STATUS_APPLICATION,
        Loan::STATUS_IN_REVIEW,
        Loan::STATUS_APPROVED,
    ];

    public function __construct(
        private readonly FormulaPolicyRegistry $formulaPolicyRegistry,
        private readonly OpenLoanAccounts $openLoanAccounts,
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

            if (! in_array($lockedLoan->status, self::ASSESSABLE_STATUSES, true)) {
                throw new InvalidArgumentException(__('loans.setup_charges_not_assessable_after_disbursement'));
            }

            // Mise en place opens the dossier's divisionary accounts before any
            // charge is assessed, so a guarantee collected next credits the
            // loan's own liability account rather than the shared control.
            // Idempotent; also backfills legs whose mapping was configured late.
            $this->openLoanAccounts->ensure($lockedLoan);

            $existing = DB::table('loan_charge_assessments')
                ->where('loan_id', $lockedLoan->id)
                ->whereIn('charge_type', ['dossier_fee', 'principal_tax', 'dossier_fee_tax', 'guarantee_deposit'])
                ->orderBy('charge_type')
                ->get();

            // Charge rows alone cannot prove the assessment ran: a product whose
            // components are all financed (or all zero-rated) inserts none, and
            // an empty result would re-run the whole calculation on every call.
            // The loan's own projection columns are written unconditionally, so
            // they are the durable marker.
            if ($existing->isNotEmpty() || $this->alreadyAssessed($lockedLoan)) {
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

            $dossierFee = $this->dossierFee($principal, $product);
            $principalTaxBase = $this->principalTaxBase($principal, $product);
            $principalTax = $this->principalTax($principalTaxBase, $product);
            $dossierFeeTax = $this->dossierFeeTax($dossierFee, $product);
            $guaranteeDeposit = $this->guaranteeDeposit($principal, $product);
            $insurance = $this->insurance($principal, $product);

            $charges = [];
            if ($dossierFee > 0 && ! $product->isInstallmentComponentFinanced('fees')) {
                $charges[] = $this->insertCharge($lockedLoan->id, 'dossier_fee', $principal, $this->rate($product->fee_rate), $dossierFee, $currency, [
                    'refundable' => false,
                    'non_refundable_after' => 'setup_approval',
                    'stakeholder_section' => 6,
                ]);
            }

            if ($principalTax > 0 && ! $product->isInstallmentComponentFinanced('tax')) {
                $charges[] = $this->insertCharge($lockedLoan->id, 'principal_tax', $principalTaxBase, $this->rate($product->tax_rate), $principalTax, $currency, [
                    'tax_base' => 'principal_plus_interest',
                    'stakeholder_section' => 7,
                ]);
            }

            if ($dossierFeeTax > 0 && ! $product->isInstallmentComponentFinanced('tax')) {
                $charges[] = $this->insertCharge($lockedLoan->id, 'dossier_fee_tax', $dossierFee, $this->rate($product->dossier_fee_tax_rate), $dossierFeeTax, $currency, [
                    'tax_base' => 'dossier_fee',
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
                'dossier_fees_tax_minor' => $dossierFeeTax,
                'principal_tax_minor' => $principalTax,
                'guarantee_deposit_amount_minor' => $guaranteeDeposit,
                'insurance_amount_minor' => $insurance,
            ])->save();

            // Sorted, because the repeat branch above reads back ordered by
            // charge_type. A caller indexing the array positionally must not see
            // a different order on the first call than on every later one.
            usort($charges, static fn (array $a, array $b): int => strcmp(
                is_string($a['charge_type']) ? $a['charge_type'] : '',
                is_string($b['charge_type']) ? $b['charge_type'] : '',
            ));

            return [
                'loan' => $lockedLoan->refresh(),
                'charges' => $charges,
                'insurance_amount_minor' => $insurance,
            ];
        });
    }

    private function alreadyAssessed(Loan $loan): bool
    {
        return $loan->dossier_fees_minor !== null
            || $loan->principal_tax_minor !== null
            || $loan->dossier_fees_tax_minor !== null
            || $loan->guarantee_deposit_amount_minor !== null
            || $loan->insurance_amount_minor !== null;
    }

    private function dossierFee(int $principal, LoanProduct $product): int
    {
        if ($product->fee_rate === null) {
            return 0;
        }

        return $this->percentOf($principal, $product->fee_rate);
    }

    /**
     * The VAT on the credit itself. Its base is the granted principal plus the
     * total flat interest — the stakeholder-approved base, unchanged by the
     * introduction of a separate dossier-fee VAT. Taxing the principal alone
     * would quietly stop taxing interest and reprice every loan.
     */
    private function principalTaxBase(int $principal, LoanProduct $product): int
    {
        return $principal + $this->totalFlatInterest($principal, $product);
    }

    private function principalTax(int $taxBase, LoanProduct $product): int
    {
        if ($product->tax_rate === null) {
            return 0;
        }

        return $this->percentOf($taxBase, $product->tax_rate);
    }

    private function totalFlatInterest(int $principal, LoanProduct $product): int
    {
        if ($product->interest_rate === null) {
            return 0;
        }

        return $this->percentOf($principal, $product->interest_rate);
    }

    private function dossierFeeTax(int $dossierFee, LoanProduct $product): int
    {
        if ($product->dossier_fee_tax_rate === null) {
            return 0;
        }

        return $this->percentOf($dossierFee, $product->dossier_fee_tax_rate);
    }

    /**
     * « La valeur du dépôt de garantie est en pourcentage, pas en FCFA. » The
     * value is a rate on the granted principal — there is no fixed-amount form,
     * so a deposit scales with the loan the way the approved policy says
     * (10 % of granted principal).
     */
    private function guaranteeDeposit(int $principal, LoanProduct $product): int
    {
        if ($product->guarantee_deposit_value === null) {
            return 0;
        }

        return $this->percentOf($principal, $product->guarantee_deposit_value);
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

    /**
     * Every setup charge is a money amount that has to land on a whole minor
     * unit, so all of them round half-up — the ordinary convention for a charge.
     *
     * The rounding mode belongs on `dividedBy`, not on a following `toScale`.
     * `dividedBy('100')` with no scale inherits the operand's scale and rounds
     * with UNNECESSARY, so it throws before any later `toScale` is consulted:
     * a 100 001 principal at a 2 % rate raised RoundingNecessaryException, which
     * is a RuntimeException and escaped the caller's InvalidArgumentException
     * handler as a 500 rather than a 422.
     */
    private function percentOf(int $baseMinor, mixed $rate): int
    {
        return BigDecimal::of((string) $baseMinor)
            ->multipliedBy(BigDecimal::of($this->numericString($rate)))
            ->dividedBy('100', 0, RoundingMode::HALF_UP)
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
