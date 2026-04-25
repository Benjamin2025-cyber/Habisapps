<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Controller;
use Spatie\QueryBuilder\QueryBuilder;

abstract class BaseController extends Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /** @param array<array-key, mixed> $meta */
    protected function respondSuccess(
        mixed $data = null,
        string $message = 'Success',
        array $meta = [],
        int $status = 200,
    ): JsonResponse {
        return ApiResponse::success($data, $message, $meta, $status);
    }

    protected function respondCreated(
        mixed $data = null,
        string $message = 'Resource created successfully',
    ): JsonResponse {
        return ApiResponse::created($data, $message);
    }

    protected function respondError(
        string $message = 'An error occurred',
        mixed $errors = null,
        int $status = 400,
    ): JsonResponse {
        return ApiResponse::error($message, $errors, $status);
    }

    protected function respondNotFound(
        string $message = 'Resource not found',
    ): JsonResponse {
        return ApiResponse::notFound($message);
    }

    protected function respondUnauthorized(
        string $message = 'Unauthorized',
    ): JsonResponse {
        return ApiResponse::unauthorized($message);
    }

    protected function respondForbidden(
        string $message = 'Forbidden',
    ): JsonResponse {
        return ApiResponse::forbidden($message);
    }

    protected function respondUnprocessable(
        string $message = 'Validation failed',
        mixed $errors = null,
    ): JsonResponse {
        return ApiResponse::unprocessable($message, $errors);
    }

    /**
     * @param  class-string<Model>|\Illuminate\Database\Eloquent\Builder<Model> $subject
     * @return QueryBuilder<Model>
     */
    protected function newQueryBuilder(string|\Illuminate\Database\Eloquent\Builder $subject): QueryBuilder
    {
        return QueryBuilder::for($subject);
    }
}
