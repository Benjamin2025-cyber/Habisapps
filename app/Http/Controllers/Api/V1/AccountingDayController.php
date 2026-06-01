<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\AccountingDays\AccountingDayWorkflow;
use App\Http\Controllers\BaseController;
use App\Http\Requests\CloseAccountingDayRequest;
use App\Http\Requests\OpenAccountingDayRequest;
use App\Http\Requests\ReopenAccountingDayRequest;
use App\Http\Resources\AccountingDayCollection;
use App\Models\AccountingDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountingDayController extends BaseController
{
    public function __construct(
        private readonly AccountingDayWorkflow $workflow,
    ) {}

    public function index(Request $request): AccountingDayCollection|JsonResponse
    {
        return $this->workflow->index($request);
    }

    public function current(Request $request): JsonResponse
    {
        return $this->workflow->current($request);
    }

    public function show(Request $request, AccountingDay $accountingDay): JsonResponse
    {
        return $this->workflow->show($request, $accountingDay);
    }

    public function open(OpenAccountingDayRequest $request): JsonResponse
    {
        return $this->workflow->open($request);
    }

    public function startClose(Request $request, AccountingDay $accountingDay): JsonResponse
    {
        return $this->workflow->startClose($request, $accountingDay);
    }

    public function close(CloseAccountingDayRequest $request, AccountingDay $accountingDay): JsonResponse
    {
        return $this->workflow->close($request, $accountingDay);
    }

    public function reopen(ReopenAccountingDayRequest $request, AccountingDay $accountingDay): JsonResponse
    {
        return $this->workflow->reopen($request, $accountingDay);
    }
}
