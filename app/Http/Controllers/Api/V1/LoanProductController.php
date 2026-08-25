<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreLoanProductRequest;
use App\Http\Requests\UpdateLoanProductRequest;
use App\Http\Resources\LoanProductCollection;
use App\Http\Resources\LoanProductResource;
use App\Models\LoanProduct;
use App\Models\User;
use App\Support\Finance\LoanProductFormulaPolicySnapshotter;
use App\Support\Security\SecurityAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $query = LoanProduct::query()->latest();

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
        $policyErrors = $this->formulaPolicySnapshotter->approvalErrors(LoanProduct::attachedPolicyAttributes());
        if ($policyErrors !== []) {
            return $this->respondUnprocessable(errors: $policyErrors);
        }

        $product = LoanProduct::query()->create($request->validated());

        $this->securityAudit->record('loan.product.created', actor: $request->user(), subject: $product, request: $request);

        return $this->respondCreated(LoanProductResource::make($product), 'Loan product created successfully');
    }

    public function show(Request $request, LoanProduct $loanProduct): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $loanProduct)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(LoanProductResource::make($loanProduct));
    }

    public function update(UpdateLoanProductRequest $request, LoanProduct $loanProduct): JsonResponse
    {
        // Deliberately not gated on formula-policy approval, unlike store().
        // The policy set is model-imposed and identical for every product, so
        // the gate is a pure function of config: no payload can satisfy it and
        // no edit can make it worse. Applying it here would refuse a rename or
        // a status correction on every existing product the moment a gate is
        // un-approved — while destroy() writes through the same saving() hook
        // regardless. Creation is where an unapproved set is newly attached, and
        // loan creation is still fail-closed via the snapshotter.
        $validated = $request->validated();

        $rangeErrors = $this->combinedRangeErrors($loanProduct, $validated);
        if ($rangeErrors !== []) {
            return $this->respondUnprocessable(errors: $rangeErrors);
        }

        $loanProduct->fill($validated);
        $loanProduct->save();

        $this->securityAudit->record('loan.product.updated', actor: $request->user(), subject: $loanProduct, properties: [
            'changed_fields' => array_keys($validated),
        ], request: $request);

        return $this->respondSuccess(LoanProductResource::make($loanProduct->refresh()), 'Loan product updated successfully');
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
            $errors['max_amount_minor'] = [__('Maximum loan amount must be greater than or equal to minimum loan amount.')];
        }

        $minTerm = array_key_exists('min_term_count', $validated) ? $validated['min_term_count'] : $loanProduct->min_term_count;
        $maxTerm = array_key_exists('max_term_count', $validated) ? $validated['max_term_count'] : $loanProduct->max_term_count;
        $minTermInt = $this->nullableInt($minTerm);
        $maxTermInt = $this->nullableInt($maxTerm);
        if ($minTermInt !== null && $maxTermInt !== null && $maxTermInt < $minTermInt) {
            $errors['max_term_count'] = [__('Maximum term must be greater than or equal to minimum term.')];
        }

        $minGrace = array_key_exists('min_grace_period_days', $validated) ? $validated['min_grace_period_days'] : $loanProduct->min_grace_period_days;
        $maxGrace = array_key_exists('max_grace_period_days', $validated) ? $validated['max_grace_period_days'] : $loanProduct->max_grace_period_days;
        $minGraceInt = $this->nullableInt($minGrace);
        $maxGraceInt = $this->nullableInt($maxGrace);
        if ($minGraceInt !== null && $maxGraceInt !== null && $maxGraceInt < $minGraceInt) {
            $errors['max_grace_period_days'] = [__('Maximum grace period must be greater than or equal to minimum grace period.')];
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

    private function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : null;
    }
}
