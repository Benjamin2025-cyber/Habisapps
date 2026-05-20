<?php

declare(strict_types=1);

namespace App\Application\FxExchange;

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

final class FxSetupWorkflow extends BaseController
{
    private const array AUTHORIZATION_TYPES = [
        'credit_institution',
        'emf',
        'postal_administration',
        'dedicated_bureau',
        'sub_delegate',
    ];

    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    public function storeAuthorization(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'authorization_reference' => ['required', 'string', 'max:191'],
            'authorization_type' => ['required', Rule::in(self::AUTHORIZATION_TYPES)],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],
            'supports_purchase' => ['sometimes', 'boolean'],
            'supports_sale' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        try {
            $row = DB::transaction(function () use ($validated, $request): object {
                $type = (string) $validated['authorization_type'];
                $supportsPurchase = $this->boolValue($validated['supports_purchase'] ?? true, true);
                $supportsSale = $this->boolValue($validated['supports_sale'] ?? true, true);
                if ($type === 'sub_delegate' && $supportsSale === true) {
                    throw new InvalidArgumentException('Sub-delegate authorizations may not support sale operations (BEAC Instruction 009).');
                }
                if ($supportsPurchase === false && $supportsSale === false) {
                    throw new InvalidArgumentException('Authorization must support at least one of purchase or sale.');
                }

                $id = DB::table('fx_authorizations')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $this->agencyIdByPublicId($validated['agency_public_id'] ?? null),
                    'authorization_reference' => (string) $validated['authorization_reference'],
                    'authorization_type' => $type,
                    'effective_from' => (string) $validated['effective_from'],
                    'effective_to' => $this->nullableString($validated['effective_to'] ?? null),
                    'status' => 'active',
                    'supports_purchase' => $supportsPurchase,
                    'supports_sale' => $supportsSale,
                    'metadata' => $this->jsonOrNull($validated['metadata'] ?? null),
                    'created_by_user_id' => $request->user()?->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('fx_authorizations')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Authorization could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['fx_authorization' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('fx.authorization.created', actor: $actor, properties: [
            'authorization_public_id' => $this->rowString($row, 'public_id'),
            'authorization_type' => $this->rowString($row, 'authorization_type'),
        ], request: $request);

        return $this->respondCreated($this->authorizationPayload($row), 'Currency exchange authorization recorded successfully');
    }

    public function storeCurrency(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'code' => ['required', 'string', 'size:3', 'unique:currencies,code'],
            'name' => ['required', 'string', 'max:255'],
            'minor_unit' => ['sometimes', 'integer', 'min:0', 'max:8'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'archived'])],
            'is_base_currency' => ['sometimes', 'boolean'],
        ])->validate();

        $code = mb_strtoupper((string) $validated['code']);
        $id = DB::table('currencies')->insertGetId([
            'code' => $code,
            'name' => (string) $validated['name'],
            'minor_unit' => isset($validated['minor_unit']) && is_numeric($validated['minor_unit']) ? (int) $validated['minor_unit'] : 2,
            'is_base_currency' => $this->boolValue($validated['is_base_currency'] ?? false, false),
            'status' => is_string($validated['status'] ?? null) && $validated['status'] !== '' ? $validated['status'] : 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->securityAudit->record('fx.currency.created', actor: $actor, properties: ['code' => $code], request: $request);

        $row = DB::table('currencies')->where('id', $id)->first();
        if (! is_object($row)) {
            return $this->respondUnprocessable(errors: ['currency' => ['Currency row could not be reloaded.']]);
        }

        return $this->respondCreated($this->currencyPayload($row), 'Currency reference recorded successfully');
    }

    private function actor(Request $request): ?User
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin') ? $actor : null;
    }

    private function agencyIdByPublicId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $agency = DB::table('agencies')->where('public_id', $publicId)->first(['id']);

        return is_object($agency) && is_numeric($agency->id) ? (int) $agency->id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizationPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'authorization_reference' => $this->rowString($row, 'authorization_reference'),
            'authorization_type' => $this->rowString($row, 'authorization_type'),
            'effective_from' => $this->rowNullableString($row, 'effective_from'),
            'effective_to' => $this->rowNullableString($row, 'effective_to'),
            'status' => $this->rowString($row, 'status'),
            'supports_purchase' => (bool) (((array) $row)['supports_purchase'] ?? false),
            'supports_sale' => (bool) (((array) $row)['supports_sale'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currencyPayload(object $row): array
    {
        return [
            'code' => $this->rowString($row, 'code'),
            'name' => $this->rowString($row, 'name'),
            'minor_unit' => $this->rowInt($row, 'minor_unit'),
            'is_base_currency' => (bool) (((array) $row)['is_base_currency'] ?? false),
            'status' => $this->rowString($row, 'status'),
        ];
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function rowInt(object $row, string $key): int
    {
        $value = ((array) $row)[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function boolValue(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            return in_array(mb_strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return $default;
    }

    private function jsonOrNull(mixed $value): ?string
    {
        return is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : null;
    }
}
