<?php

declare(strict_types=1);

namespace App\Application\Hr;

use App\Http\Controllers\BaseController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class HrLeaveWorkflow extends BaseController
{
    public function storeLeaveRequest(Request $request, string $employeePublicId): JsonResponse
    {
        if (! $this->requireHrAccess($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'leave_type' => ['required', 'string', 'max:64'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $employee = DB::table('hr_employees')->where('public_id', $employeePublicId)->first(['id']);
        if (! is_object($employee)) {
            return $this->respondUnprocessable(errors: ['hr_employee' => [__('Employee is invalid.')]]);
        }

        $publicId = (string) Str::ulid();
        DB::table('hr_leave_requests')->insert([
            'public_id' => $publicId,
            'hr_employee_id' => $this->rowInt($employee, 'id'),
            'leave_type' => (string) $validated['leave_type'],
            'starts_on' => (string) $validated['starts_on'],
            'ends_on' => (string) $validated['ends_on'],
            'status' => 'pending',
            'reason' => $this->nullableString($validated['reason'] ?? null),
            'requested_by_user_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->respondCreated([
            'public_id' => $publicId,
            'leave_type' => (string) $validated['leave_type'],
            'status' => 'pending',
        ], 'Leave request created');
    }

    public function reviewLeaveRequest(Request $request, string $leavePublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
        ])->validate();

        try {
            $row = DB::transaction(function () use ($actor, $leavePublicId, $validated): object {
                $leave = DB::table('hr_leave_requests')
                    ->where('public_id', $leavePublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($leave)) {
                    throw new InvalidArgumentException('Leave request is invalid.');
                }
                if ($this->rowString($leave, 'status') !== 'pending') {
                    throw new InvalidArgumentException('Leave request has already been reviewed.');
                }
                $requestedBy = $this->rowNullableInt($leave, 'requested_by_user_id');
                if ($requestedBy !== null && $requestedBy === $actor->id) {
                    throw new InvalidArgumentException('Requester cannot approve their own leave request.');
                }

                $newStatus = $validated['decision'] === 'approve' ? 'approved' : 'rejected';
                DB::table('hr_leave_requests')->where('id', $this->rowInt($leave, 'id'))->update([
                    'status' => $newStatus,
                    'approved_by_user_id' => $newStatus === 'approved' ? $actor->id : null,
                    'approved_at' => $newStatus === 'approved' ? now() : null,
                    'updated_at' => now(),
                ]);

                $updated = DB::table('hr_leave_requests')->where('id', $this->rowInt($leave, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Leave request could not be reloaded.');
                }

                return $updated;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['hr_leave_request' => [$exception->getMessage()]]);
        }

        return $this->respondSuccess([
            'public_id' => $this->rowString($row, 'public_id'),
            'status' => $this->rowString($row, 'status'),
        ], 'Leave request reviewed');
    }

    private function requireHrAccess(Request $request): bool
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return false;
        }

        return $actor->hasRole('platform-admin') || $actor->hasRole('hr-manager') || $actor->hasPermissionTo('hr.employee.manage');
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }

    private function rowNullableInt(object $row, string $key): ?int
    {
        $value = ((array) $row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
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
}
