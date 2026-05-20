<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use App\Http\Controllers\BaseController;
use App\Models\EmfRegulatoryAccount;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class RegulatorySourceWorkflow extends BaseController
{
    private const array AUTHORITIES = ['cobac', 'beac', 'cima', 'ohada', 'cnps', 'aaoifi', 'other'];

    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    public function storeSource(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'authority' => ['required', Rule::in(self::AUTHORITIES)],
            'reference' => ['required', 'string', 'max:191'],
            'title' => ['required', 'string', 'max:255'],
            'effective_date' => ['sometimes', 'nullable', 'date'],
            'checksum' => ['required', 'string', 'regex:/^[A-Fa-f0-9]{32,128}$/'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $existing = DB::table('regulatory_sources')
            ->where('authority', $validated['authority'])
            ->where('reference', $validated['reference'])
            ->where('effective_date', $validated['effective_date'] ?? null)
            ->first();
        if (is_object($existing)) {
            return $this->respondUnprocessable(errors: ['regulatory_source' => ['A source with this authority/reference/effective_date triple already exists.']]);
        }

        $id = DB::table('regulatory_sources')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'authority' => (string) $validated['authority'],
            'reference' => (string) $validated['reference'],
            'title' => (string) $validated['title'],
            'effective_date' => $this->nullableString($validated['effective_date'] ?? null),
            'checksum' => mb_strtolower((string) $validated['checksum']),
            'imported_by_user_id' => $actor->id,
            'imported_at' => now(),
            'metadata' => $this->jsonOrNull($validated['metadata'] ?? null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('regulatory_sources')->where('id', $id)->first();
        if (! is_object($row)) {
            return $this->respondUnprocessable(errors: ['regulatory_source' => ['Source could not be reloaded.']]);
        }

        $this->securityAudit->record('regulatory.source.created', actor: $actor, properties: [
            'authority' => $this->rowString($row, 'authority'),
            'reference' => $this->rowString($row, 'reference'),
        ], request: $request);

        return $this->respondCreated($this->sourcePayload($row), 'Regulatory source registered');
    }

    public function loadEmfAccounts(Request $request, string $sourcePublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'accounts' => ['required', 'array', 'min:1'],
            'accounts.*.code' => ['required', 'string', 'max:64'],
            'accounts.*.name' => ['required', 'string', 'max:255'],
            'accounts.*.account_class' => ['sometimes', 'nullable', 'string', 'max:32'],
            'accounts.*.parent_code' => ['sometimes', 'nullable', 'string', 'max:64'],
        ])->validate();

        try {
            $imported = DB::transaction(function () use ($sourcePublicId, $validated): array {
                $source = DB::table('regulatory_sources')->where('public_id', $sourcePublicId)->first(['id']);
                if (! is_object($source)) {
                    throw new InvalidArgumentException('Regulatory source is invalid.');
                }
                $sourceId = $this->rowInt($source, 'id');

                $accountsInput = is_array($validated['accounts'] ?? null) ? $validated['accounts'] : [];

                $codeMap = [];
                $imported = [];
                foreach ($accountsInput as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $code = is_string($row['code'] ?? null) ? $row['code'] : '';
                    if ($code === '') {
                        continue;
                    }
                    $name = is_string($row['name'] ?? null) ? $row['name'] : '';
                    $accountClass = is_string($row['account_class'] ?? null) && $row['account_class'] !== '' ? $row['account_class'] : null;
                    $parentCode = is_string($row['parent_code'] ?? null) && $row['parent_code'] !== '' ? $row['parent_code'] : null;

                    $existing = DB::table('emf_regulatory_accounts')->where('code', $code)->first(['id']);
                    if (is_object($existing)) {
                        throw new InvalidArgumentException('EMF regulatory account code already exists: '.$code.'.');
                    }

                    $parentId = null;
                    if ($parentCode !== null) {
                        if (isset($codeMap[$parentCode])) {
                            $parentId = $codeMap[$parentCode];
                        } else {
                            $parent = DB::table('emf_regulatory_accounts')->where('code', $parentCode)->first(['id']);
                            if (! is_object($parent)) {
                                throw new InvalidArgumentException('Parent code not found in import batch or existing accounts: '.$parentCode.'.');
                            }
                            $parentId = $this->rowInt($parent, 'id');
                        }
                    }

                    $accountId = DB::table('emf_regulatory_accounts')->insertGetId([
                        'public_id' => (string) Str::ulid(),
                        'regulatory_source_id' => $sourceId,
                        'code' => $code,
                        'name' => $name,
                        'account_class' => $accountClass,
                        'parent_emf_regulatory_account_id' => $parentId,
                        'status' => EmfRegulatoryAccount::STATUS_ACTIVE,
                        'metadata' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $codeMap[$code] = $accountId;
                    $imported[] = ['code' => $code, 'name' => $name];
                }

                return $imported;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['emf_account_import' => [$exception->getMessage()]]);
        }

        $actor = $request->user();
        if ($actor instanceof User) {
            $this->securityAudit->record('regulatory.emf_accounts.imported', actor: $actor, properties: [
                'source_public_id' => $sourcePublicId,
                'count' => count($imported),
            ], request: $request);
        }

        return $this->respondCreated([
            'source_public_id' => $sourcePublicId,
            'imported_count' => count($imported),
            'imported' => $imported,
        ], 'EMF regulatory accounts imported');
    }

    /**
     * @return array<string, mixed>
     */
    private function sourcePayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'authority' => $this->rowString($row, 'authority'),
            'reference' => $this->rowString($row, 'reference'),
            'title' => $this->rowString($row, 'title'),
            'effective_date' => $this->rowNullableString($row, 'effective_date'),
            'checksum' => $this->rowString($row, 'checksum'),
            'imported_at' => $this->rowNullableString($row, 'imported_at'),
        ];
    }

    private function requirePlatformAdmin(Request $request): bool
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin');
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

    private function jsonOrNull(mixed $value): ?string
    {
        return is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : null;
    }
}
