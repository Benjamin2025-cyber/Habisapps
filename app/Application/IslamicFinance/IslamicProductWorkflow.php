<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

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

final class IslamicProductWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly IslamicProductReadinessService $readiness,
    ) {}

    public function storeProduct(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'code' => ['required', 'string', 'max:64', 'unique:islamic_products,code'],
            'name' => ['required', 'string', 'max:255'],
            'contract_type' => ['required', Rule::in(['murabaha'])],
            'default_margin_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'rules' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $id = DB::transaction(function () use ($validated): int {
            $agencyId = $this->idByPublicId('agencies', $validated['agency_public_id'] ?? null);

            return DB::table('islamic_products')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agencyId,
                'code' => (string) $validated['code'],
                'name' => (string) $validated['name'],
                'contract_type' => (string) $validated['contract_type'],
                'default_margin_rate' => is_numeric($validated['default_margin_rate'] ?? null) ? (float) $validated['default_margin_rate'] : null,
                'status' => 'draft',
                'rules' => isset($validated['rules']) ? json_encode($validated['rules'], JSON_THROW_ON_ERROR) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $row = DB::table('islamic_products')->where('id', $id)->first();
        if (! is_object($row)) {
            return $this->respondUnprocessable(errors: ['islamic_product' => ['Product could not be reloaded.']]);
        }

        $this->securityAudit->record('islamic.product.created', actor: $actor, properties: [
            'product_public_id' => $this->rowString($row, 'public_id'),
            'code' => $this->rowString($row, 'code'),
            'contract_type' => $this->rowString($row, 'contract_type'),
        ], request: $request);

        return $this->respondCreated($this->productPayload($row), 'Islamic product created');
    }

    public function storeComplianceReview(Request $request, string $productPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'checklist' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($productPublicId, $validated, $actor): object {
                $product = DB::table('islamic_products')->where('public_id', $productPublicId)->lockForUpdate()->first();
                if (! is_object($product)) {
                    throw new InvalidArgumentException('Islamic product is invalid.');
                }
                if ($this->rowString($product, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft products can be submitted for Sharia compliance review.');
                }

                $id = DB::table('islamic_compliance_reviews')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_product_id' => $this->rowInt($product, 'id'),
                    'islamic_financing_id' => null,
                    'requested_by_user_id' => $actor->id,
                    'status' => 'pending',
                    'decision' => 'pending',
                    'comments' => $this->nullableString($validated['comments'] ?? null),
                    'checklist' => isset($validated['checklist']) ? json_encode($validated['checklist'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('islamic_compliance_reviews')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Compliance review could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_compliance_review' => [$exception->getMessage()]]);
        }

        return $this->respondCreated($this->complianceReviewPayload($row), 'Sharia compliance review requested');
    }

    public function reviewCompliance(Request $request, string $reviewPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($reviewPublicId, $validated, $actor): object {
                $review = DB::table('islamic_compliance_reviews')
                    ->where('public_id', $reviewPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($review)) {
                    throw new InvalidArgumentException('Compliance review is invalid.');
                }
                if ($this->rowString($review, 'status') !== 'pending') {
                    throw new InvalidArgumentException('Compliance review has already been decided.');
                }
                $requesterId = $this->rowNullableInt($review, 'requested_by_user_id');
                if ($requesterId !== null && $requesterId === $actor->id) {
                    throw new InvalidArgumentException('Requester cannot review their own compliance request.');
                }

                $newDecision = $validated['decision'] === 'approve' ? 'approved' : 'rejected';

                if ($newDecision === 'approved') {
                    $productId = $this->rowNullableInt($review, 'islamic_product_id');
                    if ($productId !== null) {
                        $product = DB::table('islamic_products')->where('id', $productId)->lockForUpdate()->first();
                        if (! is_object($product)) {
                            throw new InvalidArgumentException('Islamic product is invalid.');
                        }
                        $failures = $this->readiness->activationFailures($product);
                        if ($failures !== []) {
                            throw new StandardsBaselineFailure($failures);
                        }
                    }
                }

                DB::table('islamic_compliance_reviews')->where('id', $this->rowInt($review, 'id'))->update([
                    'status' => $newDecision,
                    'decision' => $newDecision,
                    'reviewed_by_user_id' => $actor->id,
                    'reviewed_at' => now(),
                    'comments' => $this->nullableString($validated['comments'] ?? null),
                    'updated_at' => now(),
                ]);

                if ($newDecision === 'approved') {
                    $productId = $this->rowNullableInt($review, 'islamic_product_id');
                    if ($productId !== null) {
                        DB::table('islamic_products')->where('id', $productId)->update([
                            'status' => 'approved',
                            'updated_at' => now(),
                        ]);
                    }
                }

                $updated = DB::table('islamic_compliance_reviews')->where('id', $this->rowInt($review, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Compliance review could not be reloaded.');
                }

                return $updated;
            });
        } catch (StandardsBaselineFailure $failure) {
            return $this->respondUnprocessable(errors: ['islamic_standards_baseline' => $failure->failures]);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_compliance_review' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.compliance.reviewed', actor: $actor, properties: [
            'review_public_id' => $this->rowString($row, 'public_id'),
            'status' => $this->rowString($row, 'status'),
        ], request: $request);

        return $this->respondSuccess($this->complianceReviewPayload($row), 'Compliance review completed');
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'code' => $this->rowString($row, 'code'),
            'name' => $this->rowString($row, 'name'),
            'contract_type' => $this->rowString($row, 'contract_type'),
            'status' => $this->rowString($row, 'status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function complianceReviewPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'status' => $this->rowString($row, 'status'),
            'decision' => $this->rowString($row, 'decision'),
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

        return is_scalar($value) ? (string) $value : null;
    }
}
