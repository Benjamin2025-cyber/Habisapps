<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Accounting\ResultAppropriationWorkflow;
use App\Http\Controllers\BaseController;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Affectation du résultat. Transport only; every guard lives in the workflow.
 */
final class ResultAppropriationController extends BaseController
{
    public function __construct(
        private readonly ResultAppropriationWorkflow $workflow,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{result_appropriations: array<int, \App\Http\Resources\ResultAppropriationResource>}, errors: null, meta: null}')]
    public function index(Request $request): JsonResponse
    {
        return $this->workflow->index($request);
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: \App\Http\Resources\ResultAppropriationResource, errors: null, meta: null}')]
    public function store(Request $request): JsonResponse
    {
        return $this->workflow->store($request);
    }
}
