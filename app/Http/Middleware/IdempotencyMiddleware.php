<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class IdempotencyMiddleware
{
    private const string HEADER = 'Idempotency-Key';

    private const int TTL_MINUTES = 1440;

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

        $cacheKey = 'idempotency:' . hash('sha256', $keyString . '|' . $request->path());

        /** @var array{body: array<array-key, mixed>, status: int, headers: array<string, string>}|null $cached */
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return response()->json(
                $cached['body'],
                $cached['status'],
                $cached['headers']
            );
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            Cache::put($cacheKey, [
                'body' => $response->getData(true),
                'status' => $response->getStatusCode(),
                'headers' => $this->flattenHeaders($response->headers->all()),
            ], now()->addMinutes(self::TTL_MINUTES));
        }

        return $response;
    }

    /**
     * @param array<array-key, array<string>> $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flat = [];

        foreach ($headers as $name => $values) {
            $flat[$name] = implode(', ', $values);
        }

        return $flat;
    }
}
