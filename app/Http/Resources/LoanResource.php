<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Loan;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Loan */
final class LoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $loan = $this->resource;
        if (! $loan instanceof Loan) {
            return [
                'public_id' => null,
                'loan_number' => null,
                'status' => null,
                'processing_level' => null,
                'client_public_id' => null,
                'agency_public_id' => null,
                'loan_product_public_id' => null,
                'credit_agent_public_id' => null,
                'amortization_account_public_id' => null,
                'unpaid_account_public_id' => null,
                'recovery_account_public_id' => null,
                'transfer_account_public_id' => null,
                'requested_amount_minor' => null,
                'approved_principal_minor' => null,
                'currency' => null,
                'applied_on' => null,
                'approved_on' => null,
                'disbursed_on' => null,
                'closed_on' => null,
                'purpose' => null,
                'sector_public_id' => null,
                'sub_sector_public_id' => null,
                'financed_activity_code' => null,
                'activity_address' => null,
                'entrepreneur_address' => null,
                'first_installment_date' => null,
                'number_of_installments' => null,
                'grace_period_duration' => null,
                'tranche_duration' => null,
                'total_loan_duration' => null,
                'dossier_fees_minor' => null,
                'dossier_fees_tax_minor' => null,
                'guarantee_deposit_amount_minor' => null,
                'insurance_amount_minor' => null,
                'outstanding_principal_minor' => null,
                'installment_amount_minor' => null,
                'total_unpaid_amount_minor' => null,
                'due_amount_minor' => null,
                'global_outstanding_amount_minor' => null,
                'total_principal_repaid_minor' => null,
                'total_interest_repaid_minor' => null,
                'total_penalties_paid_minor' => null,
                'installments_repaid_count' => null,
                'last_repayment_date' => null,
                'next_repayment_date' => null,
                'formula_policy_snapshot' => null,
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        return [
            'public_id' => $loan->public_id,
            'loan_number' => $loan->loan_number,
            'status' => $loan->status,
            'processing_level' => $loan->processing_level,
            'client_public_id' => $loan->client?->public_id,
            'agency_public_id' => $loan->agency?->public_id,
            'loan_product_public_id' => $loan->loanProduct?->public_id,
            'credit_agent_public_id' => $loan->creditAgent?->public_id,
            'amortization_account_public_id' => $loan->amortizationAccount?->public_id,
            'unpaid_account_public_id' => $loan->unpaidAccount?->public_id,
            'recovery_account_public_id' => $loan->recoveryAccount?->public_id,
            'transfer_account_public_id' => $loan->transferAccount?->public_id,
            'requested_amount_minor' => $loan->requested_amount_minor,
            'approved_principal_minor' => $loan->approved_principal_minor,
            'currency' => $loan->currency,
            'applied_on' => $this->formatDateOnly($loan->applied_on),
            'approved_on' => $this->formatDateOnly($loan->approved_on),
            'disbursed_on' => $this->formatDateOnly($loan->disbursed_on),
            'closed_on' => $this->formatDateOnly($loan->closed_on),
            'purpose' => $loan->purpose,
            'sector_public_id' => $loan->sector?->public_id,
            'sub_sector_public_id' => $loan->subSector?->public_id,
            'financed_activity_code' => $loan->financed_activity_code,
            'activity_address' => $loan->activity_address,
            'entrepreneur_address' => $loan->entrepreneur_address,
            'first_installment_date' => $this->formatDateOnly($loan->first_installment_date),
            'number_of_installments' => $loan->number_of_installments,
            'grace_period_duration' => $loan->grace_period_duration,
            'tranche_duration' => $loan->tranche_duration,
            'total_loan_duration' => $loan->total_loan_duration,
            'dossier_fees_minor' => $loan->dossier_fees_minor,
            'dossier_fees_tax_minor' => $loan->dossier_fees_tax_minor,
            'guarantee_deposit_amount_minor' => $loan->guarantee_deposit_amount_minor,
            'insurance_amount_minor' => $loan->insurance_amount_minor,
            'outstanding_principal_minor' => $loan->outstanding_principal_minor,
            'installment_amount_minor' => $loan->installment_amount_minor,
            'total_unpaid_amount_minor' => $loan->total_unpaid_amount_minor,
            'due_amount_minor' => $loan->due_amount_minor,
            'global_outstanding_amount_minor' => $loan->global_outstanding_amount_minor,
            'total_principal_repaid_minor' => $loan->total_principal_repaid_minor,
            'total_interest_repaid_minor' => $loan->total_interest_repaid_minor,
            'total_penalties_paid_minor' => $loan->total_penalties_paid_minor,
            'installments_repaid_count' => $loan->installments_repaid_count,
            'last_repayment_date' => $this->formatDateOnly($loan->last_repayment_date),
            'next_repayment_date' => $this->formatDateOnly($loan->next_repayment_date),
            'formula_policy_snapshot' => $loan->formula_policy_snapshot,
            'created_at' => $loan->created_at?->toISOString(),
            'updated_at' => $loan->updated_at?->toISOString(),
        ];
    }

    private function formatDateOnly(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
