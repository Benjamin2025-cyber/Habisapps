<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class IdempotencyMiddleware
{
    private const string HEADER = 'Idempotency-Key';

    private const int TTL_MINUTES = 1440;

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

        $keyString = is_array($key) ? implode(',', $key) : $key;

        if (strlen($keyString) > 255) {
            return response()->json([
                'success' => false,
                'message' => 'Idempotency-Key must be 255 characters or fewer.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $responseCacheKey = $this->responseCacheKey($request, $keyString);
        $lockKey = $this->lockCacheKey($request, $keyString);
        $fingerprint = $this->fingerprint($request);

        /** @var array{fingerprint: string, body: array<array-key, mixed>, status: int, headers: array<string, string>}|null $cached */
        $cached = Cache::get($responseCacheKey);
        $replayedResponse = $this->replayCachedResponse($cached, $fingerprint);

        if ($replayedResponse !== null) {
            return $replayedResponse;
        }

        try {
            $response = Cache::lock($lockKey, self::LOCK_SECONDS)->block(
                self::WAIT_SECONDS,
                function () use ($fingerprint, $next, $request, $responseCacheKey): Response {
                    /** @var array{fingerprint: string, body: array<array-key, mixed>, status: int, headers: array<string, string>}|null $cached */
                    $cached = Cache::get($responseCacheKey);
                    $replayedResponse = $this->replayCachedResponse($cached, $fingerprint);

                    if ($replayedResponse !== null) {
                        return $replayedResponse;
                    }

                    $response = $next($request);

                    if ($response instanceof JsonResponse
                        && $response->getStatusCode() >= 200
                        && $response->getStatusCode() < 300) {
                        Cache::put($responseCacheKey, [
                            'fingerprint' => $fingerprint,
                            'body' => $response->getData(true),
                            'status' => $response->getStatusCode(),
                            'headers' => $this->cacheableHeaders($response->headers),
                        ], now()->addMinutes(self::TTL_MINUTES));
                    }

                    return $response;
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
     * @param  array{fingerprint?: mixed, body?: mixed, status?: mixed, headers?: mixed}|null  $cached
     */
    private function replayCachedResponse(?array $cached, string $fingerprint): ?JsonResponse
    {
        if (! is_array($cached)) {
            return null;
        }

        $cachedFingerprint = $cached['fingerprint'] ?? null;

        if (! is_string($cachedFingerprint)) {
            return null;
        }

        if (! hash_equals($cachedFingerprint, $fingerprint)) {
            return ApiResponse::error(
                'Idempotency-Key has already been used for a different request.',
                null,
                Response::HTTP_CONFLICT
            );
        }

        $body = $cached['body'] ?? [];
        $status = $cached['status'] ?? Response::HTTP_OK;
        $headers = $cached['headers'] ?? [];

        if (! is_array($body) || ! is_int($status) || ! is_array($headers)) {
            return null;
        }

        return response()
            ->json($body, $status, $headers)
            ->withHeaders(['Idempotency-Replayed' => 'true']);
    }

    private function responseCacheKey(Request $request, string $key): string
    {
        return 'idempotency:response:'.hash('sha256', $request->method().'|'.$request->path().'|'.$key);
    }

    private function lockCacheKey(Request $request, string $key): string
    {
        return 'idempotency:lock:'.hash('sha256', $request->method().'|'.$request->path().'|'.$key);
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
                'tenant' => Context::get('tenant_id'),
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
