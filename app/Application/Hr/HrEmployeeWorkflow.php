<?php

declare(strict_types=1);

namespace App\Application\Hr;

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

final class HrEmployeeWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    public function storeEmployee(Request $request): JsonResponse
    {
        if (! $this->requireHrAccess($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'user_public_id' => ['sometimes', 'nullable', 'string', 'exists:users,public_id'],
            'first_name' => ['required', 'string', 'max:128'],
            'last_name' => ['required', 'string', 'max:128'],
            'employee_number' => ['sometimes', 'nullable', 'string', 'max:64', 'unique:hr_employees,employee_number'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:128'],
            'service_name' => ['sometimes', 'nullable', 'string', 'max:128'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'identity_number' => ['sometimes', 'nullable', 'string', 'max:128'],
            'hired_on' => ['sometimes', 'nullable', 'date'],
            'contract_type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'base_salary_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $canSeeSalary = $actor->hasRole('platform-admin') || $actor->hasPermissionTo('hr.salary.view');
        $agencyId = $this->idByPublicId('agencies', $validated['agency_public_id'] ?? null);
        $userId = $this->idByPublicId('users', $validated['user_public_id'] ?? null);

        $employeeNumber = is_string($validated['employee_number'] ?? null) && $validated['employee_number'] !== ''
            ? $validated['employee_number']
            : 'EMP-'.Str::upper(Str::random(8));

        $id = DB::transaction(function () use ($validated, $agencyId, $userId, $employeeNumber): int {
            $id = DB::table('hr_employees')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'user_id' => $userId,
                'agency_id' => $agencyId,
                'employee_number' => $employeeNumber,
                'first_name' => (string) $validated['first_name'],
                'last_name' => (string) $validated['last_name'],
                'phone_number' => $this->nullableString($validated['phone_number'] ?? null),
                'email' => $this->nullableString($validated['email'] ?? null),
                'identity_number' => $this->nullableString($validated['identity_number'] ?? null),
                'job_title' => $this->nullableString($validated['job_title'] ?? null),
                'service_name' => $this->nullableString($validated['service_name'] ?? null),
                'hired_on' => $this->nullableString($validated['hired_on'] ?? null),
                'contract_type' => $this->nullableString($validated['contract_type'] ?? null),
                'base_salary_minor' => is_numeric($validated['base_salary_minor'] ?? null) ? (int) $validated['base_salary_minor'] : null,
                'currency' => is_string($validated['currency'] ?? null) && $validated['currency'] !== '' ? $validated['currency'] : 'XAF',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('hr_employee_agency_history')->insert([
                'public_id' => (string) Str::ulid(),
                'hr_employee_id' => $id,
                'agency_id' => $agencyId,
                'starts_on' => $this->nullableString($validated['hired_on'] ?? null) ?? now()->toDateString(),
                'ends_on' => null,
                'reason' => 'hire',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $id;
        });

        $row = DB::table('hr_employees')->where('id', $id)->first();
        if (! is_object($row)) {
            return $this->respondUnprocessable(errors: ['hr_employee' => ['Employee could not be reloaded.']]);
        }

        $this->securityAudit->record('hr.employee.created', actor: $actor, properties: [
            'employee_public_id' => $this->rowString($row, 'public_id'),
            'employee_number' => $this->rowString($row, 'employee_number'),
        ], request: $request);

        return $this->respondCreated($this->employeePayload($row, $canSeeSalary), 'Employee record created');
    }

    public function attachEmployeeDocument(Request $request, string $employeePublicId): JsonResponse
    {
        if (! $this->requireHrAccess($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'document_public_id' => ['required', 'string', 'exists:documents,public_id'],
            'document_type' => ['sometimes', 'nullable', 'string', 'max:64'],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($employeePublicId, $validated): array {
                $employee = DB::table('hr_employees')->where('public_id', $employeePublicId)->first();
                if (! is_object($employee)) {
                    throw new InvalidArgumentException('Employee is invalid.');
                }

                $document = DB::table('documents')->where('public_id', (string) $validated['document_public_id'])->first();
                if (! is_object($document)) {
                    throw new InvalidArgumentException('Document is invalid.');
                }

                $employeeAgencyId = $this->rowNullableInt($employee, 'agency_id');
                $documentAgencyId = $this->rowInt($document, 'agency_id');
                if ($employeeAgencyId !== null && $documentAgencyId !== $employeeAgencyId) {
                    throw new InvalidArgumentException('Document must belong to the employee agency.');
                }

                $employeeId = $this->rowInt($employee, 'id');
                $documentId = $this->rowInt($document, 'id');

                $existing = DB::table('hr_employee_documents')
                    ->where('hr_employee_id', $employeeId)
                    ->where('document_id', $documentId)
                    ->first();

                $documentType = $this->nullableString($validated['document_type'] ?? null);
                $created = false;
                if (! is_object($existing)) {
                    DB::table('hr_employee_documents')->insert([
                        'hr_employee_id' => $employeeId,
                        'document_id' => $documentId,
                        'document_type' => $documentType,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $created = true;
                }

                return [
                    'document_public_id' => $this->rowString($document, 'public_id'),
                    'document_type' => $documentType,
                    'created' => $created,
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['hr_employee_document' => [$exception->getMessage()]]);
        }

        return $this->respondSuccess(
            data: [
                'document_public_id' => $result['document_public_id'],
                'document_type' => $result['document_type'],
            ],
            message: $result['created'] ? 'Employee document attached' : 'Employee document already attached',
            status: $result['created'] ? 201 : 200,
        );
    }

    public function storeContractVersion(Request $request, string $employeePublicId): JsonResponse
    {
        if (! $this->requireHrAccess($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'contract_type' => ['required', Rule::in(['CDD', 'CDI'])],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'base_salary_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'document_public_id' => ['sometimes', 'nullable', 'string', 'exists:documents,public_id'],
        ])->validate();

        try {
            $row = DB::transaction(function () use ($employeePublicId, $validated): object {
                $employee = DB::table('hr_employees')->where('public_id', $employeePublicId)->first(['id', 'agency_id']);
                if (! is_object($employee)) {
                    throw new InvalidArgumentException('Employee is invalid.');
                }
                $employeeId = $this->rowInt($employee, 'id');

                if ((string) $validated['contract_type'] === 'CDD' && ! is_string($validated['ends_on'] ?? null)) {
                    throw new InvalidArgumentException('CDD contracts require an ends_on date.');
                }

                $previous = DB::table('hr_contracts')
                    ->where('hr_employee_id', $employeeId)
                    ->where('status', 'active')
                    ->orderByDesc('version')
                    ->first();
                $version = is_object($previous) ? $this->rowInt($previous, 'version') + 1 : 1;
                $predecessorId = is_object($previous) ? $this->rowInt($previous, 'id') : null;

                if ($predecessorId !== null) {
                    DB::table('hr_contracts')->where('id', $predecessorId)->update([
                        'status' => 'superseded',
                        'updated_at' => now(),
                    ]);
                }

                $documentId = null;
                if (is_string($validated['document_public_id'] ?? null) && $validated['document_public_id'] !== '') {
                    $document = DB::table('documents')->where('public_id', $validated['document_public_id'])->first(['id', 'agency_id']);
                    if (! is_object($document)) {
                        throw new InvalidArgumentException('Document is invalid.');
                    }
                    $employeeAgencyId = $this->rowNullableInt($employee, 'agency_id');
                    if ($employeeAgencyId !== null && $this->rowInt($document, 'agency_id') !== $employeeAgencyId) {
                        throw new InvalidArgumentException('Contract document must belong to the employee agency.');
                    }
                    $documentId = $this->rowInt($document, 'id');
                }

                $id = DB::table('hr_contracts')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'hr_employee_id' => $employeeId,
                    'contract_number' => 'CTR-'.Str::upper(Str::random(8)).'-V'.$version,
                    'version' => $version,
                    'predecessor_contract_id' => $predecessorId,
                    'contract_type' => (string) $validated['contract_type'],
                    'starts_on' => (string) $validated['starts_on'],
                    'ends_on' => $this->nullableString($validated['ends_on'] ?? null),
                    'base_salary_minor' => is_numeric($validated['base_salary_minor'] ?? null) ? (int) $validated['base_salary_minor'] : null,
                    'currency' => is_string($validated['currency'] ?? null) && $validated['currency'] !== '' ? $validated['currency'] : 'XAF',
                    'status' => 'active',
                    'document_id' => $documentId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('hr_contracts')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Contract could not be reloaded.');
                }
                $endsOn = $this->rowNullableString($row, 'ends_on');
                if ($endsOn !== null) {
                    DB::table('notification_deliveries')->insertOrIgnore([
                        'public_id' => (string) Str::ulid(),
                        'notification_template_id' => null,
                        'recipient_type' => 'hr',
                        'recipient_id' => null,
                        'channel' => 'internal',
                        'category' => 'contract_expiry',
                        'idempotency_key' => 'hr-contract-expiry:'.$this->rowString($row, 'public_id'),
                        'destination' => 'hr',
                        'subject' => 'HR contract expiry',
                        'body' => 'Contract '.$this->rowString($row, 'contract_number').' expires on '.$endsOn.'.',
                        'status' => 'pending',
                        'scheduled_at' => now(),
                        'metadata' => json_encode([
                            'hr_contract_public_id' => $this->rowString($row, 'public_id'),
                            'hr_employee_id' => $employeeId,
                            'ends_on' => $endsOn,
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['hr_contract' => [$exception->getMessage()]]);
        }

        return $this->respondCreated([
            'public_id' => $this->rowString($row, 'public_id'),
            'contract_number' => $this->rowString($row, 'contract_number'),
            'version' => $this->rowInt($row, 'version'),
            'contract_type' => $this->rowString($row, 'contract_type'),
            'starts_on' => $this->rowNullableString($row, 'starts_on'),
            'ends_on' => $this->rowNullableString($row, 'ends_on'),
            'status' => $this->rowString($row, 'status'),
        ], 'Contract version created');
    }

    /**
     * @return array<string, mixed>
     */
    private function employeePayload(object $row, bool $canSeeSalary): array
    {
        $payload = [
            'public_id' => $this->rowString($row, 'public_id'),
            'employee_number' => $this->rowString($row, 'employee_number'),
            'first_name' => $this->rowString($row, 'first_name'),
            'last_name' => $this->rowString($row, 'last_name'),
            'agency_public_id' => $this->publicIdById('agencies', $this->rowNullableInt($row, 'agency_id')),
            'status' => $this->rowString($row, 'status'),
            'currency' => $this->rowString($row, 'currency'),
        ];
        if ($canSeeSalary) {
            $payload['base_salary_minor'] = $this->rowNullableInt($row, 'base_salary_minor');
        }

        return $payload;
    }

    private function requireHrAccess(Request $request): bool
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return false;
        }

        return $actor->hasRole('platform-admin') || $actor->hasRole('hr-manager') || $actor->hasPermissionTo('hr.employee.manage');
    }

    private function idByPublicId(string $table, mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $row = DB::table($table)->where('public_id', $publicId)->first(['id']);

        return is_object($row) && is_numeric($row->id) ? (int) $row->id : null;
    }

    private function publicIdById(string $table, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $row = DB::table($table)->where('id', $id)->first(['public_id']);

        return is_object($row) && is_string($row->public_id) ? $row->public_id : null;
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
