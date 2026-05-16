<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Models\Client;
use App\Models\ClientKycReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UpdateClientKycStatus
{
    public function handle(
        Client $client,
        User $actor,
        string $targetStatus,
        ?string $reason = null,
        ?string $comment = null,
    ): Client {
        if ($client->kyc_status === $targetStatus) {
            return $client;
        }

        if (! $this->isTransitionAllowed($client->kyc_status, $targetStatus)) {
            throw ValidationException::withMessages([
                'kyc_status' => ['Invalid KYC transition.'],
            ]);
        }

        $previousStatus = $client->kyc_status;

        DB::transaction(function () use ($client, $actor, $targetStatus, $reason, $comment, $previousStatus): void {
            $update = [
                'kyc_status' => $targetStatus,
            ];

            if ($targetStatus === Client::KYC_STATUS_PENDING_REVIEW) {
                $update['kyc_submitted_at'] = now();
                $update['kyc_submitted_by_user_id'] = $actor->id;
            }

            if ($targetStatus === Client::KYC_STATUS_VERIFIED) {
                $update['kyc_verified_at'] = now();
                $update['kyc_verified_by_user_id'] = $actor->id;
                $update['kyc_rejected_at'] = null;
                $update['kyc_rejection_reason'] = null;
            }

            if ($targetStatus === Client::KYC_STATUS_REJECTED) {
                $update['kyc_rejected_at'] = now();
                $update['kyc_rejection_reason'] = $reason;
            }

            if ($targetStatus === Client::KYC_STATUS_SUSPENDED) {
                $update['kyc_suspended_at'] = now();
            }

            if ($targetStatus === Client::KYC_STATUS_ARCHIVED) {
                $update['kyc_archived_at'] = now();
                $update['status'] = Client::STATUS_ARCHIVED;
            }

            $client->update($update);

            ClientKycReview::query()->create([
                'public_id' => (string) Str::ulid(),
                'client_id' => $client->id,
                'agency_id' => $client->agency_id,
                'previous_kyc_status' => $previousStatus,
                'new_kyc_status' => $targetStatus,
                'reason' => $reason,
                'comment' => $comment,
                'acted_by_user_id' => $actor->id,
            ]);
        });

        return $client->refresh();
    }

    private function isTransitionAllowed(string $current, string $target): bool
    {
        $allowed = [
            Client::KYC_STATUS_DRAFT => [
                Client::KYC_STATUS_PENDING_REVIEW,
                Client::KYC_STATUS_ARCHIVED,
            ],
            Client::KYC_STATUS_PENDING_REVIEW => [
                Client::KYC_STATUS_VERIFIED,
                Client::KYC_STATUS_REJECTED,
                Client::KYC_STATUS_ARCHIVED,
                Client::KYC_STATUS_SUSPENDED,
            ],
            Client::KYC_STATUS_REJECTED => [
                Client::KYC_STATUS_PENDING_REVIEW,
                Client::KYC_STATUS_ARCHIVED,
            ],
            Client::KYC_STATUS_VERIFIED => [
                Client::KYC_STATUS_SUSPENDED,
                Client::KYC_STATUS_ARCHIVED,
            ],
            Client::KYC_STATUS_SUSPENDED => [
                Client::KYC_STATUS_PENDING_REVIEW,
                Client::KYC_STATUS_ARCHIVED,
            ],
            Client::KYC_STATUS_ARCHIVED => [],
        ];

        return in_array($target, $allowed[$current] ?? [], true);
    }
}
