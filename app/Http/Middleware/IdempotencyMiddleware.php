<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiIdempotencyKey;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class IdempotencyMiddleware
{
    private const string HEADER = 'Idempotency-Key';

    private const int LOCK_SECONDS = 30;

    private const int WAIT_SECONDS = 5;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') && ! $request->isMethod('PATCH')) {
            return $next($request);
        }

        $key = $request->header(self::HEADER);

        if ($key === null) {
            return $next($request);
        }

        $keyString = $key;

        if (strlen($keyString) > 255) {
            return response()->json([
                'success' => false,
                'message' => 'Idempotency-Key must be 255 characters or fewer.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $actorContext = $this->actorContext($request);
        $scopeHash = $this->scopeHash($request, $keyString, $actorContext);
        $lockKey = $this->lockCacheKey($scopeHash);
        $fingerprint = $this->fingerprint($request);

        try {
            $response = Cache::lock($lockKey, self::LOCK_SECONDS)->block(
                self::WAIT_SECONDS,
                function () use ($actorContext, $fingerprint, $keyString, $next, $request, $scopeHash): Response {
                    $transactionResponse = DB::transaction(function () use ($actorContext, $fingerprint, $keyString, $next, $request, $scopeHash): Response {
                        $record = (new ApiIdempotencyKey)->newQuery()
                            ->where('scope_hash', $scopeHash)
                            ->first();

                        if ($record !== null) {
                            $replayedResponse = $this->replayStoredResponse($record, $fingerprint);

                            if ($replayedResponse !== null) {
                                return $replayedResponse;
                            }
                        } else {
                            $record = (new ApiIdempotencyKey)->newQuery()->create([
                                'key' => $keyString,
                                'method' => $request->method(),
                                'path' => $request->path(),
                                'actor_context' => $actorContext,
                                'scope_hash' => $scopeHash,
                                'request_fingerprint' => $fingerprint,
                                'expires_at' => now()->addMinutes($this->ttlMinutes()),
                            ]);
                        }

                        $response = $next($request);

                        if ($response instanceof JsonResponse
                            && $response->getStatusCode() >= 200
                            && $response->getStatusCode() < 300) {
                            $record->forceFill([
                                'response_body' => $response->getData(true),
                                'response_status' => $response->getStatusCode(),
                                'response_headers' => $this->cacheableHeaders($response->headers),
                                'completed_at' => now(),
                            ])->save();
                        }

                        return $response;
                    });

                    return $transactionResponse;
                }
            );

            if ($response instanceof Response) {
                return $response;
            }

            return ApiResponse::error(
                'Internal server error',
                null,
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        } catch (LockTimeoutException) {
            return ApiResponse::error(
                'A request with this Idempotency-Key is already being processed.',
                null,
                Response::HTTP_CONFLICT
            );
        }
    }

    /**
     * Replay a completed operation or reject key reuse with a different payload.
     */
    private function replayStoredResponse(ApiIdempotencyKey $record, string $fingerprint): ?JsonResponse
    {
        if (! hash_equals($record->request_fingerprint, $fingerprint)) {
            return ApiResponse::error(
                'Idempotency-Key has already been used for a different request.',
                null,
                Response::HTTP_CONFLICT
            );
        }

        $body = $record->response_body;
        $status = $record->response_status;
        $headers = $record->response_headers ?? [];

        if (! is_array($body) || ! is_int($status)) {
            return null;
        }

        return response()
            ->json($body, $status, $headers)
            ->withHeaders(['Idempotency-Replayed' => 'true']);
    }

    private function scopeHash(Request $request, string $key, string $actorContext): string
    {
        return hash('sha256', implode('|', [
            $request->method(),
            $request->path(),
            $actorContext,
            $key,
        ]));
    }

    private function lockCacheKey(string $scopeHash): string
    {
        return 'idempotency:lock:'.$scopeHash;
    }

    private function fingerprint(Request $request): string
    {
        try {
            return hash('sha256', json_encode([
                'method' => $request->method(),
                'path' => $request->path(),
                'query' => $this->normalize($request->query->all()),
                'body' => $this->normalize($request->request->all()),
                'actor' => $this->actorContext($request),
            ], JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return hash('sha256', $request->method().'|'.$request->path().'|'.$this->actorContext($request));
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function normalize(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalize($item);

                continue;
            }

            if ($item instanceof UploadedFile) {
                $value[$key] = [
                    'client_name' => $item->getClientOriginalName(),
                    'mime_type' => $item->getClientMimeType(),
                    'size' => $item->getSize(),
                ];
            }
        }

        return $value;
    }

    private function actorContext(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof Authenticatable) {
            $identifier = $user->getAuthIdentifier();

            if (is_string($identifier) || is_int($identifier)) {
                return 'user:'.$identifier;
            }

            return 'user:unknown';
        }

        return implode('|', [
            'guest',
            (string) $request->ip(),
            (string) $request->userAgent(),
        ]);
    }

    private function ttlMinutes(): int
    {
        $value = config('security.idempotency.ttl_minutes', 1440);

        return is_int($value) && $value > 0 ? $value : 1440;
    }

    /**
     * @return array<string, string>
     */
    private function cacheableHeaders(ResponseHeaderBag $headers): array
    {
        $excludedHeaders = [
            'cache-control',
            'content-length',
            'date',
            'set-cookie',
        ];

        $cacheable = [];

        foreach ($headers->all() as $name => $values) {
            if (in_array(strtolower($name), $excludedHeaders, true)) {
                continue;
            }

            $cacheable[$name] = implode(', ', $values);
        }

        return $cacheable;
    }
}
