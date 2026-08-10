<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Accounting\ExerciseClosingWorkflow;
use App\Http\Controllers\BaseController;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Clôture annuelle. Transport only; the arithmetic and every guard live in
 * ExerciseClosingWorkflow.
 */
final class ExerciseClosingController extends BaseController
{
    public function __construct(
        private readonly ExerciseClosingWorkflow $workflow,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{exercise_closings: array<int, \App\Http\Resources\ExerciseClosingResource>}, errors: null, meta: null}')]
    public function index(Request $request): JsonResponse
    {
        return $this->workflow->index($request);
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: \App\Http\Resources\ExerciseClosingResource, errors: null, meta: null}')]
    public function store(Request $request): JsonResponse
    {
        return $this->workflow->store($request);
    }
}
