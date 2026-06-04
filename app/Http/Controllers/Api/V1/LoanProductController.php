<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreLoanProductRequest;
use App\Http\Requests\UpdateLoanProductRequest;
use App\Http\Resources\LoanProductCollection;
use App\Http\Resources\LoanProductResource;
use App\Models\LedgerAccount;
use App\Models\LoanProduct;
use App\Models\User;
use App\Support\Finance\LoanProductFormulaPolicySnapshotter;
use App\Support\Security\SecurityAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class LoanProductController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly LoanProductFormulaPolicySnapshotter $formulaPolicySnapshotter,
    ) {}

    public function index(Request $request): LoanProductCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', LoanProduct::class)) {
            return $this->respondForbidden();
        }

        $query = LoanProduct::query()->with('ledgerAccount')->latest();

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(static function (Builder $builder) use ($term): void {
                $builder->where('code', 'ilike', '%'.$term.'%')
                    ->orWhere('name', 'ilike', '%'.$term.'%')
                    ->orWhere('status', 'ilike', '%'.$term.'%')
                    ->orWhere('term_unit', 'ilike', '%'.$term.'%');
            });
        }

        return new LoanProductCollection($query->paginate(min(max($request->integer('per_page', 25), 1), 100)));
    }

    public function store(StoreLoanProductRequest $request): JsonResponse
    {
        $policyErrors = $this->formulaPolicySnapshotter->approvalErrors($request->validated());
        if ($policyErrors !== []) {
            return $this->respondUnprocessable(errors: $policyErrors);
        }

        $ledgerAccount = $this->resolveLedgerAccount($request->input('ledger_account_public_id'));
        if ($ledgerAccount === false) {
            return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account must be active.']]);
        }

        $product = LoanProduct::query()->create($this->payload($request->validated(), $ledgerAccount));

        $this->securityAudit->record('loan.product.created', actor: $request->user(), subject: $product, request: $request);

        return $this->respondCreated(LoanProductResource::make($product->loadMissing('ledgerAccount')), 'Loan product created successfully');
    }

    public function show(Request $request, LoanProduct $loanProduct): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $loanProduct)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(LoanProductResource::make($loanProduct->loadMissing('ledgerAccount')));
    }

    public function update(UpdateLoanProductRequest $request, LoanProduct $loanProduct): JsonResponse
    {
        $validated = $request->validated();
        $policyErrors = $this->formulaPolicySnapshotter->approvalErrors($validated);
        if ($policyErrors !== []) {
            return $this->respondUnprocessable(errors: $policyErrors);
        }

        $rangeErrors = $this->combinedRangeErrors($loanProduct, $validated);
        if ($rangeErrors !== []) {
            return $this->respondUnprocessable(errors: $rangeErrors);
        }

        $ledgerAccount = null;
        if (array_key_exists('ledger_account_public_id', $validated)) {
            $ledgerAccount = $this->resolveLedgerAccount($validated['ledger_account_public_id']);
            if ($ledgerAccount === false) {
                return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account must be active.']]);
            }
        }

        $loanProduct->fill($this->payload($validated, $ledgerAccount, false));
        $loanProduct->save();

        $this->securityAudit->record('loan.product.updated', actor: $request->user(), subject: $loanProduct, properties: [
            'changed_fields' => array_keys($validated),
        ], request: $request);

        return $this->respondSuccess(LoanProductResource::make($loanProduct->refresh()->loadMissing('ledgerAccount')), 'Loan product updated successfully');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, array<int, string>>
     */
    private function combinedRangeErrors(LoanProduct $loanProduct, array $validated): array
    {
        $errors = [];
        $minAmount = array_key_exists('min_amount_minor', $validated) ? $validated['min_amount_minor'] : $loanProduct->min_amount_minor;
        $maxAmount = array_key_exists('max_amount_minor', $validated) ? $validated['max_amount_minor'] : $loanProduct->max_amount_minor;
        $minAmountInt = $this->nullableInt($minAmount);
        $maxAmountInt = $this->nullableInt($maxAmount);
        if ($minAmountInt !== null && $maxAmountInt !== null && $maxAmountInt < $minAmountInt) {
            $errors['max_amount_minor'] = ['Maximum loan amount must be greater than or equal to minimum loan amount.'];
        }

        $minTerm = array_key_exists('min_term_count', $validated) ? $validated['min_term_count'] : $loanProduct->min_term_count;
        $maxTerm = array_key_exists('max_term_count', $validated) ? $validated['max_term_count'] : $loanProduct->max_term_count;
        $minTermInt = $this->nullableInt($minTerm);
        $maxTermInt = $this->nullableInt($maxTerm);
        if ($minTermInt !== null && $maxTermInt !== null && $maxTermInt < $minTermInt) {
            $errors['max_term_count'] = ['Maximum term must be greater than or equal to minimum term.'];
        }

        $minGrace = array_key_exists('min_grace_period_days', $validated) ? $validated['min_grace_period_days'] : $loanProduct->min_grace_period_days;
        $maxGrace = array_key_exists('max_grace_period_days', $validated) ? $validated['max_grace_period_days'] : $loanProduct->max_grace_period_days;
        $minGraceInt = $this->nullableInt($minGrace);
        $maxGraceInt = $this->nullableInt($maxGrace);
        if ($minGraceInt !== null && $maxGraceInt !== null && $maxGraceInt < $minGraceInt) {
            $errors['max_grace_period_days'] = ['Maximum grace period must be greater than or equal to minimum grace period.'];
        }

        return $errors;
    }

    public function destroy(Request $request, LoanProduct $loanProduct): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('delete', $loanProduct)) {
            return $this->respondForbidden();
        }

        $loanProduct->update(['status' => LoanProduct::STATUS_ARCHIVED]);
        $this->securityAudit->record('loan.product.archived', actor: $actor, subject: $loanProduct, request: $request);

        return $this->respondSuccess(message: 'Loan product archived successfully');
    }

    private function resolveLedgerAccount(mixed $publicId): LedgerAccount|false|null
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $ledgerAccount = LedgerAccount::query()->where('public_id', $publicId)->first();
        if (! $ledgerAccount instanceof LedgerAccount || $ledgerAccount->status !== LedgerAccount::STATUS_ACTIVE) {
            return false;
        }

        return $ledgerAccount;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payload(array $validated, LedgerAccount|false|null $ledgerAccount, bool $creating = true): array
    {
        $hasLedgerAccount = array_key_exists('ledger_account_public_id', $validated);
        unset($validated['ledger_account_public_id']);

        if ($creating) {
            $validated['public_id'] = (string) Str::ulid();
        }

        if ($ledgerAccount instanceof LedgerAccount || $hasLedgerAccount) {
            $validated['ledger_account_id'] = $ledgerAccount instanceof LedgerAccount ? $ledgerAccount->id : null;
        }

        return $validated;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : null;
    }
}
