<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\StoreDocumentRequest;
use App\Http\Resources\DocumentCollection;
use App\Http\Resources\DocumentResource;
use App\Models\Agency;
use App\Models\Document;
use App\Models\User;
use App\Support\Media\MediaStorageDiskResolver;
use App\Support\Security\SecurityAudit;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

final class DocumentController extends BaseController
{
    public function __construct(private readonly SecurityAudit $securityAudit) {}

    /**
     * List documents
     *
     * @authenticated
     *
     * @response DocumentCollection
     */
    public function index(Request $request): DocumentCollection|JsonResponse
    {
        $this->authorize('viewAny', Document::class);

        $agencyId = $this->resolveAgencyId($request);
        if ($agencyId === null) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $query = Document::query()->where('agency_id', $agencyId);
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(static function (Builder $builder) use ($term): void {
                $builder->where('title', 'ilike', '%'.$term.'%')
                    ->orWhere('category', 'ilike', '%'.$term.'%')
                    ->orWhere('status', 'ilike', '%'.$term.'%')
                    ->orWhere('mime_type', 'ilike', '%'.$term.'%')
                    ->orWhere('original_name', 'ilike', '%'.$term.'%');
            });
        }

        return new DocumentCollection($query->latest()->paginate($perPage));
    }

    /**
     * Upload a KYC document.
     *
     * Uploads a KYC document file and creates a corresponding document record. The file is stored securely and metadata is tracked for integrity verification.
     *
     * @body file required The document file to upload. Must be one of: pdf, jpg, jpeg, png. Maximum size: 10MB.
     * @body category required The document category (e.g., 'kyc', 'identity', 'proof_of_address'). Max length: 64 characters.
     * @body title required A descriptive title for the document. Max length: 255 characters.
     * @body metadata optional Additional metadata as key-value pairs. Each value max length: 255 characters.
     *
     * @authenticated
     *
     * @response 201 DocumentResource
     */
    #[Response(
        status: 201,
        type: 'array{success: bool, message: string, data: array{document: \App\Http\Resources\DocumentResource}, errors: null, meta: null}'
    )]
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $actor = $request->user();
        $error = null;
        $agencyId = $this->resolveAgencyId($request, $error);
        if ($agencyId === null) {
            return $error ?? $this->respondForbidden();
        }

        if ($file === null) {
            throw new RuntimeException('Validated upload payload is missing file.');
        }

        $realPath = $file->getRealPath();
        $checksum = is_string($realPath) ? hash_file('sha256', $realPath) : false;
        if (! is_string($checksum)) {
            throw new RuntimeException('Unable to compute checksum for uploaded document.');
        }

        // Resolve the destination disk and apply the configured R2 health
        // policy before any write. fail_closed rejects the upload (so no
        // orphan document row is created) when R2 is enabled but unreachable;
        // fallback_local stores on the private local disk and is audited below.
        try {
            $decision = MediaStorageDiskResolver::fromConfig()->resolveForUpload();
        } catch (InvalidArgumentException $exception) {
            return $this->respondError(
                'Media storage configuration is invalid.',
                ['code' => 'media_storage_invalid_config', 'reason' => $exception->getMessage()],
                HttpResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($decision['outcome'] === MediaStorageDiskResolver::OUTCOME_FAIL_CLOSED) {
            return $this->respondError(
                'Media storage is currently unavailable. Please retry shortly.',
                ['code' => 'media_storage_unavailable'],
                HttpResponse::HTTP_SERVICE_UNAVAILABLE
            );
        }
        $targetDisk = $decision['disk'];

        $document = DB::transaction(function () use ($actor, $agencyId, $request, $checksum, $targetDisk): Document {
            $document = Document::query()->create([
                'agency_id' => $agencyId,
                'uploaded_by_user_id' => $actor instanceof User ? $actor->id : null,
                'category' => $request->string('category')->toString(),
                'title' => $request->string('title')->toString(),
                'status' => Document::STATUS_ACTIVE,
                'metadata' => $request->input('metadata'),
            ]);

            $media = $document->addMediaFromRequest('file')
                ->sanitizingFileName(fn (string $fileName): string => $this->sanitizeFileName($fileName))
                ->toMediaCollection('kyc_documents', $targetDisk);

            $document->update([
                'disk' => $media->disk,
                'path' => $media->getPathRelativeToRoot(),
                'original_name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size_bytes' => $media->size,
                'checksum_sha256' => $checksum,
            ]);

            return $document->refresh();
        });

        $actorUser = $actor instanceof User ? $actor : null;
        $this->securityAudit->record('document.created', actor: $actorUser, subject: $document, request: $request);

        // Storage-selection audit. Properties carry only the disk name, never
        // object keys, bucket names, endpoints, or credentials.
        if ($decision['outcome'] === MediaStorageDiskResolver::OUTCOME_FALLBACK_LOCAL) {
            $this->securityAudit->record('media.storage.local_fallback_used', actor: $actorUser, subject: $document, properties: ['disk' => $document->disk], request: $request);
        } elseif ($decision['outcome'] === MediaStorageDiskResolver::OUTCOME_R2) {
            $this->securityAudit->record('media.storage.r2_selected', actor: $actorUser, subject: $document, properties: ['disk' => $document->disk], request: $request);
        }

        return $this->respondCreated(
            DocumentResource::make($document),
            'Document uploaded successfully'
        );
    }

    /**
     * Retrieve a KYC document.
     *
     * Retrieves metadata for a specific KYC document by its public ID. The file content itself is served by the separate `documents/{document}/file` endpoint.
     *
     * @authenticated
     *
     * @response DocumentResource
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{document: \App\Http\Resources\DocumentResource}, errors: null, meta: null}'
    )]
    public function show(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        return $this->respondSuccess(
            DocumentResource::make($document)
        );
    }

    /**
     * Archive a KYC document.
     *
     * Archives a KYC document by changing its status to 'archived'. This changes the domain lifecycle state without physically deleting the underlying file.
     *
     * @authenticated
     *
     * @response DocumentResource
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{document: \App\Http\Resources\DocumentResource}, errors: null, meta: null}'
    )]
    public function archive(Request $request, Document $document): JsonResponse
    {
        $this->authorize('archive', $document);

        $document->forceFill([
            'status' => Document::STATUS_ARCHIVED,
            'archived_at' => now(),
        ])->save();

        $actor = $request->user();
        $this->securityAudit->record('document.archived', actor: $actor, subject: $document, request: $request);

        return $this->respondSuccess(
            DocumentResource::make($document->refresh()),
            'Document archived successfully'
        );
    }

    /**
     * Stream a document file for display/preview.
     *
     * Authorization mirrors `show`: the actor must hold `documents.view` and
     * be scoped to the document's agency, which blocks cross-agency access.
     *
     * @authenticated
     */
    public function download(Request $request, Document $document): HttpResponse
    {
        $this->authorize('view', $document);

        $media = $document->getFirstMedia('kyc_documents');
        if ($media === null) {
            return $this->respondNotFound('Document file is not available.');
        }

        // The object may be absent on its backing disk (local or R2). Probe
        // existence first so a missing/relocated object yields a controlled
        // 404 rather than a 500 from the streaming response. The disk is read
        // from the media row itself, so local-backed and R2-backed media both
        // serve from wherever they actually live (R2-007).
        try {
            $exists = Storage::disk($media->disk)->exists($media->getPathRelativeToRoot());
        } catch (Throwable) {
            return $this->respondNotFound('Document file is not available.');
        }
        if (! $exists) {
            return $this->respondNotFound('Document file is not available.');
        }

        $actor = $request->user();
        $this->securityAudit->record('document.downloaded', actor: $actor instanceof User ? $actor : null, subject: $document, request: $request);

        // Inline response sets Content-Type from the stored mime type and an
        // inline disposition so browsers can render images/PDFs directly.
        return $media->toInlineResponse($request);
    }

    /**
     * Resolve the agency a document operation targets.
     *
     * Platform-admin and institution-scoped actors may target any agency via
     * `agency_public_id`; everyone else is constrained to their current
     * agency. When such an actor has no current agency and supplies no
     * selection, a structured 422 is returned via $error rather than an
     * unexplained 403.
     */
    private function resolveAgencyId(Request $request, ?JsonResponse &$error = null): ?int
    {
        $user = $request->user();
        if (! $user instanceof User) {
            $error = $this->respondForbidden();

            return null;
        }

        $canTargetAnyAgency = $user->hasRole('platform-admin') || $user->can('crm.scope.institution.manage');
        $currentAgencyId = $user->currentAgencyId();
        $requestedPublicId = $request->input('agency_public_id');

        if (is_string($requestedPublicId) && $requestedPublicId !== '') {
            $agency = Agency::query()->where('public_id', $requestedPublicId)->first();
            if (! $agency instanceof Agency) {
                $error = $this->respondUnprocessable(errors: ['agency_public_id' => ['Selected agency does not exist.']]);

                return null;
            }

            if ($canTargetAnyAgency || $currentAgencyId === $agency->id) {
                return $agency->id;
            }

            $error = $this->respondForbidden('You can only operate on documents within your current agency.');

            return null;
        }

        if ($currentAgencyId !== null) {
            return $currentAgencyId;
        }

        if ($canTargetAnyAgency) {
            $error = $this->respondUnprocessable(errors: ['agency_public_id' => ['Agency is required. Provide agency_public_id to select the target agency.']]);

            return null;
        }

        $error = $this->respondForbidden();

        return null;
    }

    private function sanitizeFileName(string $fileName): string
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        $safeName = (string) Str::of($name)
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '-')
            ->trim('-_.');

        if ($safeName === '') {
            $safeName = 'document';
        }

        $safeExtension = (string) Str::of($extension)
            ->lower()
            ->replaceMatches('/[^A-Za-z0-9]+/', '');

        return $safeExtension === '' ? $safeName : $safeName.'.'.$safeExtension;
    }
}
