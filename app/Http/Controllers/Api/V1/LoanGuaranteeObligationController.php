<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Resources\LoanGuaranteeObligationResource;
use App\Models\ClientGuarantor;
use App\Models\Document;
use App\Models\Loan;
use App\Models\LoanGuaranteeObligation;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class LoanGuaranteeObligationController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    public function index(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canManageGuarantees($actor) || ! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden();
        }

        $obligations = LoanGuaranteeObligation::query()
            ->with(['agency', 'loan', 'clientGuarantor', 'document', 'releasedBy'])
            ->where('loan_id', $loan->id)
            ->latest()
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return $this->respondSuccess([
            'guarantee_obligations' => LoanGuaranteeObligationResource::collection($obligations->getCollection()),
        ], meta: [
            'pagination' => [
                'current_page' => $obligations->currentPage(),
                'per_page' => $obligations->perPage(),
                'total' => $obligations->total(),
                'last_page' => $obligations->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canManageGuarantees($actor) || ! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden();
        }

        $validated = $this->validateObligation($request);
        $errors = [];
        $guarantorPublicId = $validated['client_guarantor_public_id'] ?? null;
        if (! is_string($guarantorPublicId)) {
            return $this->respondUnprocessable(errors: ['client_guarantor_public_id' => ['Selected guarantor is invalid.']]);
        }

        $guarantor = $this->resolveGuarantor($loan, $guarantorPublicId, $errors);
        $document = $this->resolveDocument($loan, $validated['document_public_id'] ?? null, $errors);
        if ($errors !== []) {
            return $this->respondUnprocessable(errors: $errors);
        }

        $obligation = LoanGuaranteeObligation::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $loan->agency_id,
            'loan_id' => $loan->id,
            'client_guarantor_id' => $guarantor->id,
            'document_id' => $document?->id,
            'obligation_type' => $validated['obligation_type'],
            'obligation_amount_minor' => $validated['obligation_amount_minor'] ?? null,
            'obligation_percentage' => $validated['obligation_percentage'] ?? null,
            'currency' => $validated['currency'] ?? $loan->currency,
            'status' => LoanGuaranteeObligation::STATUS_ACTIVE,
            'starts_on' => $validated['starts_on'] ?? null,
            'ends_on' => $validated['ends_on'] ?? null,
            'release_condition' => $validated['release_condition'] ?? 'loan_closed',
            'guarantor_identity_snapshot' => $this->snapshotGuarantor($guarantor),
        ]);

        $this->securityAudit->record('loan.guarantee_obligation.created', actor: $actor, subject: $obligation, request: $request);

        return $this->respondCreated(
            LoanGuaranteeObligationResource::make($obligation->loadMissing(['agency', 'loan', 'clientGuarantor', 'document', 'releasedBy'])),
            'Loan guarantee obligation created successfully'
        );
    }

    public function update(Request $request, Loan $loan, LoanGuaranteeObligation $obligation): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canManageGuarantees($actor) || ! $this->canAccessObligation($actor, $loan, $obligation)) {
            return $this->respondForbidden();
        }

        $validated = $this->validateObligation($request, updating: true);
        $errors = [];
        $document = array_key_exists('document_public_id', $validated)
            ? $this->resolveDocument($loan, $validated['document_public_id'], $errors)
            : null;
        if ($errors !== []) {
            return $this->respondUnprocessable(errors: $errors);
        }

        unset($validated['client_guarantor_public_id']);
        if (array_key_exists('document_public_id', $validated)) {
            unset($validated['document_public_id']);
            $validated['document_id'] = $document?->id;
        }

        if (($validated['status'] ?? null) === LoanGuaranteeObligation::STATUS_RELEASED) {
            return $this->respondUnprocessable(errors: ['status' => ['Use the release endpoint to release a guarantee obligation.']]);
        }

        $obligation->fill($validated)->save();
        $this->securityAudit->record('loan.guarantee_obligation.updated', actor: $actor, subject: $obligation, request: $request);

        return $this->respondSuccess(
            LoanGuaranteeObligationResource::make($obligation->refresh()->loadMissing(['agency', 'loan', 'clientGuarantor', 'document', 'releasedBy'])),
            'Loan guarantee obligation updated successfully'
        );
    }

    public function release(Request $request, Loan $loan, LoanGuaranteeObligation $obligation): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canManageGuarantees($actor) || ! $this->canAccessObligation($actor, $loan, $obligation)) {
            return $this->respondForbidden();
        }

        if ($loan->status !== Loan::STATUS_CLOSED) {
            return $this->respondUnprocessable(errors: ['loan' => ['Guarantee obligations can only be released after loan closure.']]);
        }

        if ($obligation->status === LoanGuaranteeObligation::STATUS_CANCELLED) {
            return $this->respondUnprocessable(errors: ['status' => ['Cancelled guarantee obligations cannot be released.']]);
        }

        $obligation->update([
            'status' => LoanGuaranteeObligation::STATUS_RELEASED,
            'released_at' => $obligation->released_at ?? now(),
            'released_by_user_id' => $actor->id,
        ]);

        $this->securityAudit->record('loan.guarantee_obligation.released', actor: $actor, subject: $obligation, request: $request);

        return $this->respondSuccess(
            LoanGuaranteeObligationResource::make($obligation->refresh()->loadMissing(['agency', 'loan', 'clientGuarantor', 'document', 'releasedBy'])),
            'Loan guarantee obligation released successfully'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validateObligation(Request $request, bool $updating = false): array
    {
        $presence = $updating ? 'sometimes' : 'required';

        return Validator::make($request->all(), [
            'client_guarantor_public_id' => [$presence, 'string', 'exists:client_guarantors,public_id'],
            'document_public_id' => ['sometimes', 'nullable', 'string', 'exists:documents,public_id'],
            'obligation_type' => [$presence, 'string', Rule::in(['personal_guarantee'])],
            'obligation_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'obligation_percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'status' => ['sometimes', Rule::in([LoanGuaranteeObligation::STATUS_ACTIVE, LoanGuaranteeObligation::STATUS_CANCELLED])],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'release_condition' => ['sometimes', 'nullable', 'string', 'max:128'],
        ])->validate();
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function resolveGuarantor(Loan $loan, string $publicId, array &$errors): ClientGuarantor
    {
        $guarantor = ClientGuarantor::query()
            ->with(['client', 'guarantorClient', 'document'])
            ->where('public_id', $publicId)
            ->first();

        if (! $guarantor instanceof ClientGuarantor
            || $guarantor->agency_id !== $loan->agency_id
            || $guarantor->client_id !== $loan->client_id
            || $guarantor->status !== ClientGuarantor::STATUS_ACTIVE
            || $guarantor->verification_status !== ClientGuarantor::VERIFICATION_VERIFIED) {
            $errors['client_guarantor_public_id'] = ['Selected guarantor must be active, verified, tied to the loan client, and belong to the loan agency.'];

            return new ClientGuarantor;
        }

        return $guarantor;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function resolveDocument(Loan $loan, mixed $publicId, array &$errors): ?Document
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $document = Document::query()->where('public_id', $publicId)->first();
        if (! $document instanceof Document || $document->agency_id !== $loan->agency_id || $document->status !== Document::STATUS_ACTIVE) {
            $errors['document_public_id'] = ['Selected document must be active and belong to the loan agency.'];

            return null;
        }

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotGuarantor(ClientGuarantor $guarantor): array
    {
        return [
            'client_guarantor_public_id' => $guarantor->public_id,
            'client_public_id' => $guarantor->client?->public_id,
            'guarantor_client_public_id' => $guarantor->guarantorClient?->public_id,
            'document_public_id' => $guarantor->document?->public_id,
            'guarantor_full_name' => $guarantor->guarantor_full_name,
            'guarantor_phone_number' => $guarantor->guarantor_phone_number,
            'relationship_type' => $guarantor->relationship_type,
            'status' => $guarantor->status,
            'verification_status' => $guarantor->verification_status,
            'starts_on' => $this->formatDate($guarantor->starts_on),
            'ends_on' => $this->formatDate($guarantor->ends_on),
            'verified_at' => $this->formatDate($guarantor->verified_at),
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function canManageGuarantees(User $actor): bool
    {
        return $actor->hasRole('platform-admin') || $actor->hasPermissionTo('loans.guarantees.manage');
    }

    private function canAccessLoanAgency(User $actor, Loan $loan): bool
    {
        return $actor->hasRole('platform-admin')
            || $actor->can('crm.scope.institution.read')
            || $this->staffAgencyScope->currentAgencyId($actor) === $loan->agency_id;
    }

    private function canAccessObligation(User $actor, Loan $loan, LoanGuaranteeObligation $obligation): bool
    {
        return $obligation->loan_id === $loan->id
            && $obligation->agency_id === $loan->agency_id
            && $this->canAccessLoanAgency($actor, $loan);
    }
}
