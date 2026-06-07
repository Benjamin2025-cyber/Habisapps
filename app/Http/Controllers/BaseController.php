<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Spatie\QueryBuilder\QueryBuilder;

abstract class BaseController extends Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /** @param array<array-key, mixed> $meta */
    protected function respondSuccess(
        mixed $data = null,
        ?string $message = null,
        array $meta = [],
        int $status = 200,
    ): JsonResponse {
        return ApiResponse::success($data, $message, $meta, $status);
    }

    protected function respondCreated(
        mixed $data = null,
        ?string $message = null,
    ): JsonResponse {
        return ApiResponse::created($data, $message);
    }

    protected function respondError(
        ?string $message = null,
        mixed $errors = null,
        int $status = 400,
    ): JsonResponse {
        return ApiResponse::error($message, $errors, $status);
    }

    protected function respondNotFound(
        ?string $message = null,
    ): JsonResponse {
        return ApiResponse::notFound($message);
    }

    protected function respondUnauthorized(
        ?string $message = null,
    ): JsonResponse {
        return ApiResponse::unauthorized($message);
    }

    protected function respondForbidden(
        ?string $message = null,
    ): JsonResponse {
        return ApiResponse::forbidden($message);
    }

    protected function respondUnprocessable(
        ?string $message = null,
        mixed $errors = null,
    ): JsonResponse {
        return ApiResponse::unprocessable($message, $errors);
    }

    /**
     * @param  class-string<Model>|Builder<Model>  $subject
     * @return QueryBuilder<Model>
     */
    protected function newQueryBuilder(string|Builder $subject): QueryBuilder
    {
        return QueryBuilder::for($subject);
    }
}
