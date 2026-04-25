<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ApiResponse
{
    private function __construct() {}

    /**
     * @param array<array-key, mixed> $meta
     * @param array<array-key, string> $headers
     */
    public static function success(
        mixed $data = null,
        string $message = 'Success',
        array $meta = [],
        int $status = Response::HTTP_OK,
        array $headers = [],
    ): JsonResponse {
        return self::build(true, $message, $data, null, $meta, $status, $headers);
    }

    /** @param array<array-key, mixed> $meta */
    public static function created(
        mixed $data = null,
        string $message = 'Resource created successfully',
        array $meta = [],
    ): JsonResponse {
        return self::success($data, $message, $meta, Response::HTTP_CREATED);
    }

    /** @param array<array-key, mixed> $meta */
    public static function error(
        string $message = 'An error occurred',
        mixed $errors = null,
        int $status = Response::HTTP_BAD_REQUEST,
        array $meta = [],
    ): JsonResponse {
        return self::build(false, $message, null, $errors, $meta, $status);
    }

    public static function notFound(
        string $message = 'Resource not found',
        mixed $errors = null,
    ): JsonResponse {
        return self::error($message, $errors, Response::HTTP_NOT_FOUND);
    }

    public static function unauthorized(
        string $message = 'Unauthorized',
    ): JsonResponse {
        return self::error($message, null, Response::HTTP_UNAUTHORIZED);
    }

    public static function forbidden(
        string $message = 'Forbidden',
    ): JsonResponse {
        return self::error($message, null, Response::HTTP_FORBIDDEN);
    }

    public static function unprocessable(
        string $message = 'Validation failed',
        mixed $errors = null,
    ): JsonResponse {
        return self::error($message, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function tooManyRequests(
        string $message = 'Too many requests',
        int $retryAfter = 60,
    ): JsonResponse {
        return self::error($message, null, Response::HTTP_TOO_MANY_REQUESTS, ['retry_after' => $retryAfter])
            ->withHeaders(['Retry-After' => (string) $retryAfter]);
    }

    /**
     * @param array<array-key, mixed> $meta
     * @param array<array-key, string> $headers
     */
    private static function build(
        bool $success,
        string $message,
        mixed $data = null,
        mixed $errors = null,
        array $meta = [],
        int $status = Response::HTTP_OK,
        array $headers = [],
    ): JsonResponse {
        $resolvedMeta = $meta === [] ? null : $meta;

        $payload = array_filter([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'meta' => $resolvedMeta,
        ], static fn (mixed $value): bool => $value !== null);

        return response()->json($payload, $status, $headers);
    }
}
