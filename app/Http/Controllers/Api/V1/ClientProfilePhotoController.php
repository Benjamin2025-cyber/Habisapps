<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\Document;
use Illuminate\Http\Request;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Serves a short-lived, signed, thumbnail-sized render of a client's profile
 * photo (API-ISSUE-006).
 *
 * The route carries the `signed` middleware so the URL is self-authorizing for
 * the duration of its signature: a browser <img> tag can fetch it without a
 * bearer token, while tampered or expired URLs are rejected with 403 before
 * reaching this controller. The signed URL is only ever minted by
 * {@see ClientResource} for an actor already authorized to
 * view the client's operational identity, and it is bound to a single client's
 * public id, so it cannot be re-pointed at another agency's files.
 */
final class ClientProfilePhotoController extends BaseController
{
    private const int THUMBNAIL_SIZE = 256;

    public function thumbnail(Request $request, Client $client): Response
    {
        $client->loadMissing('profilePhotoDocument');
        $document = $client->profilePhotoDocument;

        if (! $this->isServeableProfilePhoto($document)) {
            return $this->respondNotFound('Profile photo is not available.');
        }

        /** @var Document $document */
        $media = $document->getFirstMedia('kyc_documents');
        if ($media === null) {
            return $this->respondNotFound('Profile photo file is not available.');
        }

        $sourcePath = tempnam(sys_get_temp_dir(), 'pp_src_');
        $thumbnailPath = $sourcePath.'.jpg';
        if (! is_string($sourcePath)) {
            return $this->respondNotFound('Profile photo could not be rendered.');
        }

        try {
            $stream = $media->stream();
            $handle = fopen($sourcePath, 'wb');
            if ($handle === false) {
                return $this->respondNotFound('Profile photo could not be rendered.');
            }
            stream_copy_to_stream($stream, $handle);
            fclose($handle);
            if (is_resource($stream)) {
                fclose($stream);
            }

            // Square avatar render; always emit JPEG for a predictable type.
            Image::load($sourcePath)
                ->fit(Fit::Crop, self::THUMBNAIL_SIZE, self::THUMBNAIL_SIZE)
                ->save($thumbnailPath);

            $contents = file_get_contents($thumbnailPath);
        } catch (Throwable) {
            return $this->respondNotFound('Profile photo could not be rendered.');
        } finally {
            @unlink($sourcePath);
            @unlink($thumbnailPath);
        }

        if ($contents === false) {
            return $this->respondNotFound('Profile photo could not be rendered.');
        }

        return response($contents, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($contents),
            // Conservative, private caching: a browser may reuse the render for
            // the lifetime of the signature, but shared caches must not store it.
            'Cache-Control' => 'private, max-age=300, no-transform',
            'Content-Disposition' => 'inline; filename="profile-photo-thumbnail.jpg"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function isServeableProfilePhoto(?Document $document): bool
    {
        return $document instanceof Document
            && $document->status === Document::STATUS_ACTIVE
            && $document->category === 'profile_photo'
            && is_string($document->mime_type)
            && str_starts_with($document->mime_type, 'image/');
    }
}
