<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\UpdateInstitutionProfileRequest;
use App\Http\Resources\InstitutionProfileResource;
use App\Models\InstitutionProfile;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The institution's own identity — a singleton, so these routes take no
 * identifier. Institution *scope* is not configured here: that stays encoded as
 * `agency_id IS NULL` on ledger accounts and operation mappings.
 */
final class InstitutionProfileController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: \App\Http\Resources\InstitutionProfileResource, errors: null, meta: null}')]
    public function show(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || (! $actor->hasRole('platform-admin') && ! $actor->can('institution.profile.view'))) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(InstitutionProfileResource::make(InstitutionProfile::singleton()));
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: \App\Http\Resources\InstitutionProfileResource, errors: null, meta: null}')]
    public function update(UpdateInstitutionProfileRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = $request->validated();
        if (array_key_exists('declared_reporting_currency', $validated) && is_string($validated['declared_reporting_currency'])) {
            $validated['declared_reporting_currency'] = strtoupper($validated['declared_reporting_currency']);
        }

        $profile = InstitutionProfile::singleton();
        $profile->fill($validated);
        $profile->save();

        // The legal name, approval number and registration identifiers end up on
        // supervisory filings and issued attestations, so every change is
        // recorded with the fields that were touched.
        $this->securityAudit->record('institution.profile.updated', actor: $actor, subject: $profile, properties: [
            'fields' => array_keys($validated),
        ], request: $request);

        return $this->respondSuccess(
            InstitutionProfileResource::make($profile),
            'Institution profile updated successfully'
        );
    }
}
