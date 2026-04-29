<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class DocumentController extends BaseController
{
    public function __construct(private readonly SecurityAudit $securityAudit) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->user()?->can('documents.view') !== true) {
            return $this->respondForbidden();
        }

        $agencyId = $this->resolveAgencyId($request);
        if ($agencyId === null) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $documents = Document::query()
            ->where('agency_id', $agencyId)
            ->latest()
            ->paginate($perPage);

        return $this->respondSuccess([
            'documents' => DocumentResource::collection($documents->getCollection())->resolve(),
        ], meta: [
            'pagination' => [
                'current_page' => $documents->currentPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
                'last_page' => $documents->lastPage(),
            ],
        ]);
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
     * @response 201 {"success":true,"message":"Document uploaded successfully","data":{"document":{"public_id":"01H...","category":"kyc","title":"National ID","original_name":"id.jpg","mime_type":"image/jpeg","size_bytes":12345,"checksum_sha256":"abc123...","status":"active","metadata":null,"verified_at":null,"archived_at":null,"created_at":"2024-01-01T00:00:00+00:00"}}}
     * @response 403 {"success":false,"message":"Access denied"}
     * @response 422 {"success":false,"message":"Validation failed","errors":{"file":["The file must be a file of type: pdf, jpg, jpeg, png."]}}
     *
     * @authenticated
     */
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

        return $this->respondCreated([
            'document' => DocumentResource::make($document)->resolve(),
        ], 'Document uploaded successfully');
    }

    /**
     * Retrieve a KYC document.
     *
     * Retrieves metadata for a specific KYC document by its public ID. Note: This endpoint does not return the file content. File download is not currently exposed.
     *
     * @response 200 {"success":true,"data":{"document":{"public_id":"01H...","category":"kyc","title":"National ID","original_name":"id.jpg","mime_type":"image/jpeg","size_bytes":12345,"checksum_sha256":"abc123...","status":"active","metadata":null,"verified_at":null,"archived_at":null,"created_at":"2024-01-01T00:00:00+00:00"}}}
     * @response 403 {"success":false,"message":"Access denied"}
     * @response 404 {"success":false,"message":"Resource not found"}
     *
     * @authenticated
     */
    public function show(Request $request, Document $document): JsonResponse
    {
        if ($request->user()?->can('documents.view') !== true) {
            return $this->respondForbidden();
        }

        $requestAgencyId = $this->resolveAgencyId($request);
        if ($document->agency_id !== $requestAgencyId) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess([
            'document' => DocumentResource::make($document)->resolve(),
        ]);
    }

    /**
     * Archive a KYC document.
     *
     * Archives a KYC document by changing its status to 'archived'. This changes the domain lifecycle state without physically deleting the underlying file.
     *
     * @response 200 {"success":true,"message":"Document archived successfully","data":{"document":{"public_id":"01H...","category":"kyc","title":"National ID","original_name":"id.jpg","mime_type":"image/jpeg","size_bytes":12345,"checksum_sha256":"abc123...","status":"archived","metadata":null,"verified_at":null,"archived_at":"2024-01-01T00:00:00+00:00","created_at":"2024-01-01T00:00:00+00:00"}}}
     * @response 403 {"success":false,"message":"Access denied"}
     * @response 404 {"success":false,"message":"Resource not found"}
     *
     * @authenticated
     */
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

        return $this->respondSuccess([
            'document' => DocumentResource::make($document->refresh())->resolve(),
        ], 'Document archived successfully');
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
