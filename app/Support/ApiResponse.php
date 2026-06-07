<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ApiResponse
{
    private function __construct() {}

    /**
     * @param  array<array-key, mixed>  $meta
     * @param  array<array-key, string>  $headers
     */
    public static function success(
        mixed $data = null,
        ?string $message = null,
        array $meta = [],
        int $status = Response::HTTP_OK,
        array $headers = [],
    ): JsonResponse {
        return self::build(true, $message ?? __('api.success'), $data, null, $meta, $status, $headers);
    }

    /** @param array<array-key, mixed> $meta */
    public static function created(
        mixed $data = null,
        ?string $message = null,
        array $meta = [],
    ): JsonResponse {
        return self::success($data, $message ?? __('api.created'), $meta, Response::HTTP_CREATED);
    }

    /** @param array<array-key, mixed> $meta */
    public static function error(
        ?string $message = null,
        mixed $errors = null,
        int $status = Response::HTTP_BAD_REQUEST,
        array $meta = [],
    ): JsonResponse {
        return self::build(false, $message ?? __('api.error'), null, $errors, $meta, $status);
    }

    public static function notFound(
        ?string $message = null,
        mixed $errors = null,
    ): JsonResponse {
        return self::error($message ?? __('api.not_found'), $errors, Response::HTTP_NOT_FOUND);
    }

    public static function unauthorized(
        ?string $message = null,
    ): JsonResponse {
        return self::error($message ?? __('api.unauthorized'), null, Response::HTTP_UNAUTHORIZED);
    }

    public static function forbidden(
        ?string $message = null,
    ): JsonResponse {
        return self::error($message ?? __('api.forbidden'), null, Response::HTTP_FORBIDDEN);
    }

    public static function unprocessable(
        ?string $message = null,
        mixed $errors = null,
    ): JsonResponse {
        return self::error($message ?? __('api.validation_failed'), $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function tooManyRequests(
        ?string $message = null,
        int $retryAfter = 60,
    ): JsonResponse {
        return self::error($message ?? __('api.too_many_requests'), null, Response::HTTP_TOO_MANY_REQUESTS, ['retry_after' => $retryAfter])
            ->withHeaders(['Retry-After' => (string) $retryAfter]);
    }

    /**
     * @param  array<array-key, mixed>  $meta
     * @param  array<array-key, string>  $headers
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
        $resolvedMeta = self::resolveMeta($meta, $data, $status);
        $resolvedMessage = __($message);

        $payload = array_filter([
            'success' => $success,
            'message' => $resolvedMessage,
            'data' => $data,
            'errors' => $errors,
            'meta' => $resolvedMeta,
        ], static fn (mixed $value): bool => $value !== null);

        return response()->json($payload, $status, $headers);
    }

    /**
     * @param  array<array-key, mixed>  $meta
     * @return array<array-key, mixed>|null
     */
    private static function resolveMeta(array $meta, mixed $data, int $status): ?array
    {
        $resolved = $meta;
        $pagination = null;

        // Only synthesize default pagination when the caller supplied no meta of
        // its own; otherwise we would clobber controller-provided pagination.
        if ($meta === [] && $status >= 200 && $status < 300) {
            $request = function_exists('request') ? request() : null;
            if ($request !== null && $request->isMethod('get')) {
                $page = max($request->integer('page', 1), 1);
                $perPage = max($request->integer('per_page', 1), 1);

                $total = 1;
                if (is_array($data) && array_is_list($data)) {
                    $total = count($data);
                }

                $pagination = [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max((int) ceil($total / $perPage), 1),
                ];
            }
        }

        if ($pagination !== null) {
            $resolved['pagination'] = $pagination;
        }

        if (config('localization.meta_enabled', false) === true) {
            $resolved['locale'] = app()->getLocale();
        }

        return $resolved === [] ? null : $resolved;
    }
}
