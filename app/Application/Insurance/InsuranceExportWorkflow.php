<?php

declare(strict_types=1);

namespace App\Application\Insurance;

use App\Http\Controllers\BaseController;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class InsuranceExportWorkflow extends BaseController
{
    public function __construct(
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly InsuranceExportService $insuranceExports,
    ) {}

    public function subscriptions(Request $request): JsonResponse
    {
        return $this->export($request, 'Subscriptions export', fn (User $actor, int $agencyId): array => $this->insuranceExports->subscriptions($actor, $agencyId, $request->all()));
    }

    public function premiums(Request $request): JsonResponse
    {
        return $this->export($request, 'Premiums export', fn (User $actor, int $agencyId): array => $this->insuranceExports->premiums($actor, $agencyId, $request->all()));
    }

    public function claims(Request $request): JsonResponse
    {
        return $this->export($request, 'Claims export', fn (User $actor, int $agencyId): array => $this->insuranceExports->claims($actor, $agencyId, $request->all()));
    }

    public function commissions(Request $request): JsonResponse
    {
        return $this->export($request, 'Commissions export', fn (User $actor, int $agencyId): array => $this->insuranceExports->commissions($actor, $agencyId, $request->all()));
    }

    public function remittances(Request $request): JsonResponse
    {
        return $this->export($request, 'Remittances export', fn (User $actor, int $agencyId): array => $this->insuranceExports->remittances($actor, $agencyId, $request->all()));
    }

    public function cancellationsRefunds(Request $request): JsonResponse
    {
        $this->validateReportFilters($request, allowStatus: true);

        return $this->export($request, 'Cancellations/refunds export', fn (User $actor, int $agencyId): array => $this->insuranceExports->cancellationsRefunds($actor, $agencyId, $request->all()));
    }

    /**
     * @param  callable(User, int): array<string, mixed>  $exporter
     */
    private function export(Request $request, string $message, callable $exporter): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermissionTo('insurance.reports.export')) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(
            $exporter($actor, $this->scopedAgencyIdForReport($actor, $request->query('agency_public_id'))),
            $message,
        );
    }

    private function scopedAgencyIdForReport(User $actor, mixed $agencyPublicId): int
    {
        if ($actor->hasRole('platform-admin') && is_string($agencyPublicId) && $agencyPublicId !== '') {
            return $this->agencyId($agencyPublicId);
        }

        if ($actor->hasRole('agency-manager')) {
            $scopedId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($scopedId !== null) {
                return $scopedId;
            }
        }

        return is_string($agencyPublicId) && $agencyPublicId !== ''
            ? $this->agencyId($agencyPublicId)
            : 0;
    }

    private function agencyId(string $agencyPublicId): int
    {
        $agency = DB::table('agencies')->where('public_id', $agencyPublicId)->first(['id']);

        return is_object($agency) && is_numeric(((array) $agency)['id'] ?? null)
            ? (int) ((array) $agency)['id']
            : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateReportFilters(Request $request, bool $allowStatus): array
    {
        $rules = [
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'product_public_id' => ['sometimes', 'nullable', 'string', 'exists:insurance_products,public_id'],
            'partner_public_id' => ['sometimes', 'nullable', 'string', 'exists:insurance_partners,public_id'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
        ];

        if ($allowStatus) {
            $rules['status'] = ['sometimes', 'nullable', 'string', Rule::in([
                'active', 'inactive', 'archived', 'assessed', 'paid', 'voided', 'pending',
                'approved', 'rejected', 'settled', 'expired', 'cancelled',
            ])];
        }

        return Validator::make($request->all(), $rules)->validate();
    }
}
