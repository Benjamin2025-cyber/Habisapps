<?php

declare(strict_types=1);

namespace App\Support\DatabaseManagement;

use App\Models\DatabaseBackup;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Encapsulates all filesystem interaction for backup artifacts so that no
 * controller, workflow, or job ever touches a raw path or disk directly. Keeps
 * checksum/existence/size logic in one auditable place (ADM-DB-002/004/005).
 */
final class BackupArtifactStore
{
    public function __construct(private readonly DatabaseManagementConfig $config) {}

    public function disk(string $disk): Filesystem
    {
        return Storage::disk($disk);
    }

    /**
     * Build a deterministic, collision-resistant relative path for a new
     * artifact. The path is relative to the disk root — never an absolute local
     * path — so it is safe to persist and (for the filename) surface in the API.
     *
     * @return array{filename: string, path: string}
     */
    public function buildArtifactLocation(string $publicId, string $datePart): array
    {
        $extension = $this->config->compression() === 'gzip' ? 'dump.gz' : 'dump';
        $filename = sprintf('backup_%s_%s.%s', $datePart, Str::lower($publicId), $extension);
        $path = $this->config->pathPrefix().'/'.$filename;

        return ['filename' => $filename, 'path' => $path];
    }

    public function exists(DatabaseBackup $backup): bool
    {
        return $this->disk($backup->disk)->exists($backup->path);
    }

    public function size(DatabaseBackup $backup): ?int
    {
        if (! $this->exists($backup)) {
            return null;
        }

        return $this->disk($backup->disk)->size($backup->path);
    }

    /**
     * Stream a SHA-256 over the artifact without loading it fully into memory.
     * Returns null when the artifact is missing.
     */
    public function checksum(DatabaseBackup $backup): ?string
    {
        if (! $this->exists($backup)) {
            return null;
        }

        $stream = $this->disk($backup->disk)->readStream($backup->path);
        if (! is_resource($stream)) {
            return null;
        }

        $context = hash_init('sha256');
        while (! feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) {
                break;
            }
            hash_update($context, $chunk);
        }
        fclose($stream);

        return hash_final($context);
    }

    /**
     * @return resource|null
     */
    public function readStream(DatabaseBackup $backup)
    {
        $stream = $this->disk($backup->disk)->readStream($backup->path);

        return is_resource($stream) ? $stream : null;
    }

    /**
     * Stream the artifact to the client as an authorized download. Uses a
     * chunked stream so a large artifact is never loaded fully into memory, and
     * never exposes the absolute filesystem path.
     */
    public function download(DatabaseBackup $backup): StreamedResponse
    {
        $disk = $this->disk($backup->disk);

        return response()->streamDownload(function () use ($disk, $backup): void {
            $stream = $disk->readStream($backup->path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $backup->filename, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }

    public function delete(DatabaseBackup $backup): bool
    {
        if (! $this->disk($backup->disk)->exists($backup->path)) {
            return false;
        }

        return $this->disk($backup->disk)->delete($backup->path);
    }
}
