<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\CashOperations\TellerSessionWorkflow;
use App\Http\Controllers\BaseController;
use App\Http\Requests\CloseTellerSessionRequest;
use App\Http\Requests\StoreTellerSessionRequest;
use App\Http\Resources\TellerSessionCollection;
use App\Models\TellerSession;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TellerSessionController extends BaseController
{
    public function __construct(
        private readonly TellerSessionWorkflow $workflow,
    ) {}

    #[QueryParameter('filter[business_date]', 'Limit results to an exact business date in YYYY-MM-DD format.', type: 'string', format: 'date')]
    #[QueryParameter('filter[business_date_from]', 'Limit results to sessions on or after this business date in YYYY-MM-DD format.', type: 'string', format: 'date')]
    #[QueryParameter('filter[business_date_to]', 'Limit results to sessions on or before this business date in YYYY-MM-DD format.', type: 'string', format: 'date')]
    #[QueryParameter('filter[till_public_id]', 'Limit results to a till public ID.', type: 'string')]
    #[QueryParameter('filter[teller_user_public_id]', 'Limit results to a teller user public ID.', type: 'string')]
    #[QueryParameter('filter[status]', 'Limit results to a teller session status.', type: 'string')]
    #[QueryParameter('filter[agency_public_id]', 'Platform-admin only. Limit results to an agency public ID.', type: 'string')]
    #[QueryParameter('sort', 'Allowed values: business_date, -business_date, opened_at, -opened_at, closed_at, -closed_at, status.', type: 'string')]
    #[QueryParameter('search', 'Broad search over status, business date, currency, agency, till, and teller.', type: 'string')]
    #[QueryParameter('per_page', 'Results per page. Capped at 100.', type: 'integer')]
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
