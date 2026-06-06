<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Http\Controllers\BaseController;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClientStatsWorkflow extends BaseController
{
    public function __construct(
        private readonly ClientListQuery $clientListQuery,
    ) {}

    public function stats(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', Client::class)) {
            return $this->respondForbidden();
        }

        $built = $this->clientListQuery->build($actor, $request);
        if ($built['error'] instanceof JsonResponse) {
            return $built['error'];
        }

        // Zero-fill every client status so by_status partitions the scoped total.
        $byStatus = [
            Client::STATUS_ACTIVE => 0,
            Client::STATUS_INACTIVE => 0,
            Client::STATUS_SUSPENDED => 0,
            Client::STATUS_ARCHIVED => 0,
        ];
        $byKycStatus = [
            'pending' => 0,
            'verified' => 0,
            'rejected' => 0,
        ];

        $rows = (clone $built['query'])->toBase()->reorder()
            ->selectRaw('status, kyc_status, COUNT(*) AS row_count')
            ->groupBy('status', 'kyc_status')
            ->get();

        foreach ($rows as $row) {
            $count = is_numeric($row->row_count ?? null) ? (int) $row->row_count : 0;
            $status = (string) ($row->status ?? '');
            $kycStatus = (string) ($row->kyc_status ?? '');

            if (array_key_exists($status, $byStatus)) {
                $byStatus[$status] += $count;
            }

            $dashboardKycKey = match ($kycStatus) {
                Client::KYC_STATUS_VERIFIED => 'verified',
                Client::KYC_STATUS_REJECTED => 'rejected',
                Client::KYC_STATUS_DRAFT, Client::KYC_STATUS_PENDING_REVIEW => 'pending',
                default => null,
            };
            if ($dashboardKycKey !== null) {
                $byKycStatus[$dashboardKycKey] += $count;
            }
        }

        return $this->respondSuccess([
            'by_status' => $byStatus,
            'by_kyc_status' => $byKycStatus,
        ], 'Client statistics');
    }
}
