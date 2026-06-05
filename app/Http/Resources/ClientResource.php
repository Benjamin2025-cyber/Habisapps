<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * @mixin Client
 */
final class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Client $client */
        $client = $this->resource;

        // Two-tier client visibility (FB-PII-002):
        // - Operational identity/contact: enough to identify and serve a client
        //   on front-office screens; granted by crm.clients.identity.view.
        // - Full sensitive PII: birth/family/address/employment data; gated by
        //   crm.pii.view only. Full PII implies operational identity.
        $showFullPii = $this->canViewFullPii($request);
        $showOperationalIdentity = $showFullPii || $this->canViewOperationalIdentity($request);

        return [
            'public_id' => $client->public_id,
            'agency_public_id' => $client->relationLoaded('agency') ? $client->agency?->public_id : null,
            'profile_photo_document_public_id' => $client->relationLoaded('profilePhotoDocument') ? $client->profilePhotoDocument?->public_id : null,
            'profile_photo_thumbnail_url' => $this->profilePhotoThumbnailUrl($client, $showOperationalIdentity),
            'prospector_public_id' => $client->relationLoaded('prospector') ? $client->prospector?->public_id : null,
            'collection_agent_public_id' => $client->relationLoaded('collectionAgent') ? $client->collectionAgent?->public_id : null,
            'sector_public_id' => $client->relationLoaded('sector') ? $client->sector?->public_id : null,
            'sub_sector_public_id' => $client->relationLoaded('subSector') ? $client->subSector?->public_id : null,
            'client_reference' => $client->client_reference,
            'first_name' => $showOperationalIdentity ? $client->first_name : $this->maskName($client->first_name),
            'last_name' => $showOperationalIdentity ? $client->last_name : $this->maskName($client->last_name),
            'middle_name' => $showOperationalIdentity ? $client->middle_name : $this->maskName($client->middle_name),
            'father_name' => $showFullPii ? $client->father_name : $this->maskName($client->father_name),
            'mother_name' => $showFullPii ? $client->mother_name : $this->maskName($client->mother_name),
            'date_of_birth' => $showFullPii ? $this->formatDate($client->date_of_birth) : null,
            'place_of_birth' => $showFullPii ? $client->place_of_birth : null,
            'gender' => $showFullPii ? $client->gender : null,
            'phone_number' => $showOperationalIdentity ? $client->phone_number : $this->maskPhone($client->phone_number),
            'home_phone_number' => $showFullPii ? $client->home_phone_number : $this->maskPhone($client->home_phone_number),
            'email' => $showOperationalIdentity ? $client->email : $this->maskEmail($client->email),
            'address_line_1' => $showFullPii ? $client->address_line_1 : null,
            'address_line_2' => $showFullPii ? $client->address_line_2 : null,
            'city' => $showFullPii ? $client->city : null,
            'region' => $showFullPii ? $client->region : null,
            'occupation' => $showFullPii ? $client->occupation : null,
            'employer_name' => $showFullPii ? $client->employer_name : null,
            'business_started_on' => $this->formatDate($client->business_started_on),
            'business_activity_started_on' => $this->formatDate($client->business_activity_started_on),
            'business_address_line_1' => $showFullPii ? $client->business_address_line_1 : null,
            'business_address_line_2' => $showFullPii ? $client->business_address_line_2 : null,
            'business_city' => $showFullPii ? $client->business_city : null,
            'business_region' => $showFullPii ? $client->business_region : null,
            'collection_type' => $client->collection_type,
            'collection_frequency' => $client->collection_frequency,
            'collection_target_amount' => $client->collection_target_amount,
            // Frontend redaction flags (FB-PII-002 / FB-FE-001). These let the
            // frontend distinguish masked values from genuinely missing data.
            // - identity_redacted: operational identity/contact is masked.
            // - sensitive_pii_redacted: full sensitive PII is hidden.
            // - pii_redacted: transitional alias kept for backward compatibility;
            //   tracks the full sensitive PII tier (crm.pii.view).
            'identity_redacted' => ! $showOperationalIdentity,
            'sensitive_pii_redacted' => ! $showFullPii,
            'pii_redacted' => ! $showFullPii,
            'status' => $client->status,
            'kyc_status' => $client->kyc_status,
            'onboarded_on' => $this->formatDate($client->onboarded_on),
            'kyc_submitted_at' => $this->formatDate($client->kyc_submitted_at),
            'kyc_verified_at' => $this->formatDate($client->kyc_verified_at),
            'kyc_rejected_at' => $this->formatDate($client->kyc_rejected_at),
            'kyc_rejection_reason' => $client->kyc_rejection_reason,
            'kyc_suspended_at' => $this->formatDate($client->kyc_suspended_at),
            'kyc_archived_at' => $this->formatDate($client->kyc_archived_at),
            'created_at' => $this->formatDate($client->created_at),
            'updated_at' => $this->formatDate($client->updated_at),
        ];
    }

    private function maskName(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return mb_substr($value, 0, 1).str_repeat('*', max(0, mb_strlen($value) - 1));
    }

    /**
     * Short-lived signed thumbnail URL for the client's profile photo, exposed
     * only when the actor may view operational identity and the linked document
     * is an active, image profile photo (API-ISSUE-006). Returns null for
     * missing, archived, non-image, or unauthorized photos.
     */
    private function profilePhotoThumbnailUrl(Client $client, bool $showOperationalIdentity): ?string
    {
        if (! $showOperationalIdentity || ! $client->relationLoaded('profilePhotoDocument')) {
            return null;
        }

        $document = $client->profilePhotoDocument;
        if (! $document instanceof Document
            || $document->status !== Document::STATUS_ACTIVE
            || $document->category !== 'profile_photo'
            || ! is_string($document->mime_type)
            || ! str_starts_with($document->mime_type, 'image/')) {
            return null;
        }

        return URL::temporarySignedRoute(
            'clients.profile-photo-thumbnail',
            now()->addMinutes(5),
            ['client' => $client->public_id],
        );
    }

    private function canViewFullPii(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->checkPermissionTo('crm.pii.view');
    }

    private function canViewOperationalIdentity(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->checkPermissionTo('crm.clients.identity.view');
    }

    private function maskPhone(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).substr($value, -4);
    }

    private function maskEmail(?string $value): ?string
    {
        if ($value === null || $value === '' || ! str_contains($value, '@')) {
            return $value;
        }

        [$local, $domain] = explode('@', $value, 2);
        if ($local === '') {
            return '*@'.$domain;
        }

        return substr($local, 0, 1).str_repeat('*', max(0, strlen($local) - 1)).'@'.$domain;
    }

    private function formatDate(mixed $value): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return $value->format(DateTimeInterface::ATOM);
    }
}
