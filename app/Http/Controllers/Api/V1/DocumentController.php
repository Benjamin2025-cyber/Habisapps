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
use Illuminate\Support\Facades\Storage;

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

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $actor = $request->user();
        $agencyId = $this->resolveAgencyId($request);
        if ($agencyId === null) {
            return $this->respondForbidden();
        }

        $disk = 'local';
        $path = $file->store('documents', $disk);

        if (! is_string($path)) {
            return $this->respondError('Document could not be stored.');
        }

        $contents = Storage::disk($disk)->get($path);
        if (! is_string($contents)) {
            return $this->respondError('Stored document could not be read for verification.');
        }

        $checksum = hash('sha256', $contents);

        $document = Document::query()->create([
            'agency_id' => $agencyId,
            'uploaded_by_user_id' => $actor instanceof User ? $actor->id : null,
            'category' => $request->string('category')->toString(),
            'title' => $request->string('title')->toString(),
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'checksum_sha256' => $checksum,
            'status' => Document::STATUS_ACTIVE,
            'metadata' => $request->input('metadata'),
        ]);

        $this->securityAudit->record('document.created', actor: $actor instanceof User ? $actor : null, subject: $document, request: $request);

        return $this->respondCreated([
            'document' => DocumentResource::make($document)->resolve(),
        ], 'Document uploaded successfully');
    }

    public function show(Request $request, Document $document): JsonResponse
    {
        if ($request->user()?->can('documents.view') !== true) {
            return $this->respondForbidden();
        }

        if ($document->agency_id !== $this->resolveAgencyId($request)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess([
            'document' => DocumentResource::make($document)->resolve(),
        ]);
    }

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
}
