<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\StoreDocumentRequest;
use App\Http\Resources\DocumentCollection;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

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
        if ($request->user()?->can('documents.view') !== true) {
            return $this->respondForbidden();
        }

        $agencyId = $this->resolveAgencyId($request);
        if ($agencyId === null) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        return new DocumentCollection(
            Document::query()
                ->where('agency_id', $agencyId)
                ->latest()
                ->paginate($perPage)
        );
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
        $agencyId = $this->resolveAgencyId($request);
        if ($agencyId === null) {
            return $this->respondForbidden();
        }

        if ($file === null) {
            throw new RuntimeException('Validated upload payload is missing file.');
        }

        $realPath = $file->getRealPath();
        $checksum = is_string($realPath) ? hash_file('sha256', $realPath) : false;
        if (! is_string($checksum)) {
            throw new RuntimeException('Unable to compute checksum for uploaded document.');
        }

        $document = DB::transaction(function () use ($actor, $agencyId, $request, $checksum): Document {
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
                ->toMediaCollection('kyc_documents');

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

        $this->securityAudit->record('document.created', actor: $actor instanceof User ? $actor : null, subject: $document, request: $request);

        return $this->respondCreated(
            DocumentResource::make($document),
            'Document uploaded successfully'
        );
    }

    /**
     * Retrieve a KYC document.
     *
     * Retrieves metadata for a specific KYC document by its public ID. Note: This endpoint does not return the file content. File download is not currently exposed.
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
        if ($request->user()?->can('documents.view') !== true) {
            return $this->respondForbidden();
        }

        $requestAgencyId = $this->resolveAgencyId($request);
        if ($document->agency_id !== $requestAgencyId) {
            return $this->respondForbidden();
        }

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
        if ($request->user()?->can('documents.archive') !== true) {
            return $this->respondForbidden();
        }

        if ($document->agency_id !== $this->resolveAgencyId($request)) {
            return $this->respondForbidden();
        }

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

    private function resolveAgencyId(Request $request): ?int
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        return $user->currentAgencyId();
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
