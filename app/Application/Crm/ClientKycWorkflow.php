<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\UpdateClientKycStatusRequest;
use App\Http\Resources\ClientKycReviewResource;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\ClientIdentityDocument;
use App\Models\ClientKycReview;
use App\Models\Document;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ClientKycWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly UpdateClientKycStatus $updateClientKycStatus,
    ) {}

    public function updateKycStatus(UpdateClientKycStatusRequest $request, Client $client): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $action = $request->string('action')->toString();
        if (! $this->canApplyKycAction($actor, $client, $action)) {
            return $this->respondForbidden();
        }

        $allowExpiredIdentityOverride = $request->boolean('force_override_expired_identity', false);
        if ($allowExpiredIdentityOverride && ! $actor->hasPermissionTo('crm.kyc.override.expired_identity')) {
            return $this->respondForbidden('Expired identity override requires explicit permission.');
        }

        $transition = $this->kycTransitionTarget($action);
        if ($transition === null) {
            return $this->respondUnprocessable('Unsupported KYC action.');
        }

        if ($client->kyc_status === $transition) {
            return $this->respondSuccess(
                ClientResource::make($client->loadMissing(['agency', 'profilePhotoDocument', 'prospector', 'collectionAgent', 'sector', 'subSector'])),
                'KYC status already applied.'
            );
        }

        if ($transition === Client::KYC_STATUS_VERIFIED
            && ! $this->hasVerifiedIdentityEvidence($client, $allowExpiredIdentityOverride)) {
            return $this->respondUnprocessable('Client must have at least one active verified identity document before KYC verification.');
        }

        $allowSelfVerify = $request->boolean('allow_self_verify', false);
        if ($transition === Client::KYC_STATUS_VERIFIED
            && ! $this->canVerifySubmittedKyc($client, $actor, $allowSelfVerify)) {
            // Distinguish "you are the submitter" (resolvable via the override
            // flag + permission) from a plain authorization failure, so an
            // otherwise-permitted actor is not left guessing why approval 403s.
            if ($this->isSelfVerification($client, $actor)) {
                return $this->respondForbidden(
                    $allowSelfVerify
                        ? 'Self-verification requires the crm.kyc.override.self_verify permission.'
                        : 'You submitted this client KYC, so a different checker must verify it. To self-verify, set allow_self_verify=true and provide a reason; this also requires the crm.kyc.override.self_verify permission.'
                );
            }

            return $this->respondForbidden('KYC verification requires a checker different from the submitter.');
        }

        $previousStatus = $client->kyc_status;
        $reason = $request->input('reason');
        $comment = $request->input('comment');

        $client = $this->updateClientKycStatus->handle(
            $client,
            $actor,
            $transition,
            is_string($reason) ? $reason : null,
            is_string($comment) ? $comment : null,
        );

        $this->securityAudit->record('crm.client.kyc_status_changed', actor: $actor, subject: $client, properties: [
            'previous_kyc_status' => $previousStatus,
            'new_kyc_status' => $transition,
            'maker_checker_override_used' => $transition === Client::KYC_STATUS_VERIFIED
                && $allowSelfVerify
                && (bool) config('security.crm.kyc.enforce_maker_checker', true),
            'override_surface' => $transition === Client::KYC_STATUS_VERIFIED && $allowSelfVerify ? 'client_kyc' : null,
            'override_reason' => $transition === Client::KYC_STATUS_VERIFIED && $allowSelfVerify && is_string($reason) ? $reason : null,
            'kyc_submitted_by_user_id' => $client->kyc_submitted_by_user_id,
        ], request: $request);

        return $this->respondSuccess(
            ClientResource::make($client->loadMissing(['agency', 'profilePhotoDocument', 'prospector', 'collectionAgent', 'sector', 'subSector'])),
            'Client KYC status updated successfully'
        );
    }

    public function kycReviews(Request $request, Client $client): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewKycReviews', $client)) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $reviews = ClientKycReview::query()
            ->with(['client', 'agency', 'actedBy'])
            ->where('client_id', $client->id);
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $reviews->where(static function (Builder $builder) use ($term): void {
                $builder->where('previous_kyc_status', 'ilike', '%'.$term.'%')
                    ->orWhere('new_kyc_status', 'ilike', '%'.$term.'%')
                    ->orWhere('reason', 'ilike', '%'.$term.'%')
                    ->orWhere('comment', 'ilike', '%'.$term.'%')
                    ->orWhereHas('actedBy', static function (Builder $userBuilder) use ($term): void {
                        $userBuilder->where('name', 'ilike', '%'.$term.'%')
                            ->orWhere('email', 'ilike', '%'.$term.'%');
                    });
            });
        }
        $reviews = $reviews->latest('created_at')->paginate($perPage);

        return $this->respondSuccess(
            [
                'reviews' => ClientKycReviewResource::collection($reviews->getCollection()),
            ],
            meta: [
                'pagination' => [
                    'current_page' => $reviews->currentPage(),
                    'per_page' => $reviews->perPage(),
                    'total' => $reviews->total(),
                    'last_page' => $reviews->lastPage(),
                ],
            ]
        );
    }

    private function canApplyKycAction(User $actor, Client $client, string $action): bool
    {
        return match ($action) {
            'submit' => $actor->can('submitKyc', $client),
            'verify' => $actor->can('verifyKyc', $client),
            'reject' => $actor->can('rejectKyc', $client),
            'suspend' => $actor->can('suspendKyc', $client),
            'archive' => $actor->can('archive', $client),
            default => false,
        };
    }

    private function isSelfVerification(Client $client, User $actor): bool
    {
        return (bool) config('security.crm.kyc.enforce_maker_checker', true)
            && $client->kyc_submitted_by_user_id !== null
            && $client->kyc_submitted_by_user_id === $actor->id;
    }

    private function canVerifySubmittedKyc(Client $client, User $actor, bool $allowSelfVerify): bool
    {
        if (! (bool) config('security.crm.kyc.enforce_maker_checker', true)) {
            return true;
        }

        if ($client->kyc_submitted_by_user_id === null || $client->kyc_submitted_by_user_id !== $actor->id) {
            return true;
        }

        return $allowSelfVerify && $actor->hasPermissionTo('crm.kyc.override.self_verify');
    }

    private function kycTransitionTarget(string $action): ?string
    {
        return match ($action) {
            'submit' => Client::KYC_STATUS_PENDING_REVIEW,
            'verify' => Client::KYC_STATUS_VERIFIED,
            'reject' => Client::KYC_STATUS_REJECTED,
            'suspend' => Client::KYC_STATUS_SUSPENDED,
            'archive' => Client::KYC_STATUS_ARCHIVED,
            default => null,
        };
    }

    private function hasVerifiedIdentityEvidence(Client $client, bool $forceOverrideExpiredIdentity): bool
    {
        $query = DB::table('client_identity_documents')
            ->join('documents', 'documents.id', '=', 'client_identity_documents.document_id')
            ->where('client_id', $client->id)
            ->where('client_identity_documents.agency_id', $client->agency_id)
            ->where('client_identity_documents.status', ClientIdentityDocument::STATUS_ACTIVE)
            ->where('client_identity_documents.verification_status', ClientIdentityDocument::VERIFICATION_VERIFIED)
            ->whereNull('client_identity_documents.archived_at')
            ->where('documents.agency_id', $client->agency_id)
            ->where('documents.status', 'active')
            ->whereIn('documents.category', ['kyc', 'identity', 'proof_of_address'])
            ->whereExists(function ($mediaQuery): void {
                $mediaQuery
                    ->selectRaw('1')
                    ->from('media')
                    ->whereColumn('media.model_id', 'documents.id')
                    ->where('media.model_type', Document::class)
                    ->where('media.collection_name', 'kyc_documents');
            });

        if (! $forceOverrideExpiredIdentity) {
            $today = now()->toDateString();
            $query->where(function ($builder) use ($today): void {
                $builder->whereNull('client_identity_documents.expires_on')
                    ->orWhere('client_identity_documents.expires_on', '>=', $today);
            });
        }

        return $query->exists();
    }
}
