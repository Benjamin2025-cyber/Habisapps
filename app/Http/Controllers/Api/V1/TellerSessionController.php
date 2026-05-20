<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\CashOperations\TellerSessionWorkflow;
use App\Http\Controllers\BaseController;
use App\Http\Requests\CloseTellerSessionRequest;
use App\Http\Requests\StoreTellerSessionRequest;
use App\Http\Resources\TellerSessionCollection;
use App\Models\TellerSession;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TellerSessionController extends BaseController
{
    public function __construct(
        private readonly TellerSessionWorkflow $workflow,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{teller_sessions: array<int, \App\Http\Resources\TellerSessionResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): TellerSessionCollection|JsonResponse
    {
        return $this->workflow->index($request);
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{teller_session: \App\Http\Resources\TellerSessionResource}, errors: null, meta: null}')]
    public function store(StoreTellerSessionRequest $request): JsonResponse
    {
        return $this->workflow->store($request);
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{teller_session: \App\Http\Resources\TellerSessionResource}, errors: null, meta: null}')]
    public function show(Request $request, TellerSession $tellerSession): JsonResponse
    {
        return $this->workflow->show($request, $tellerSession);
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{teller_session: \App\Http\Resources\TellerSessionResource}, errors: null, meta: null}')]
    public function close(CloseTellerSessionRequest $request, TellerSession $tellerSession): JsonResponse
    {
        return $this->workflow->close($request, $tellerSession);
    }
}
