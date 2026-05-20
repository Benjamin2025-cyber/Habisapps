<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Accounting\AccountingBalanceWorkflow;
use App\Http\Controllers\BaseController;
use App\Http\Resources\AccountingBalanceResource;
use App\Http\Resources\AvailableBalanceResource;
use App\Models\CustomerAccount;
use App\Models\LedgerAccount;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountingBalanceController extends BaseController
{
    public function __construct(
        private readonly AccountingBalanceWorkflow $workflow,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{balance: \App\Http\Resources\AccountingBalanceResource}, errors: null, meta: null}')]
    public function ledgerAccount(Request $request, LedgerAccount $ledgerAccount): JsonResponse
    {
        return $this->workflow->ledgerAccount($request, $ledgerAccount);
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{balance: \App\Http\Resources\AccountingBalanceResource}, errors: null, meta: null}')]
    public function customerAccount(Request $request, CustomerAccount $customerAccount): JsonResponse
    {
        return $this->workflow->customerAccount($request, $customerAccount);
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{balance: \App\Http\Resources\AvailableBalanceResource}, errors: null, meta: null}')]
    public function customerAccountAvailable(Request $request, CustomerAccount $customerAccount): JsonResponse
    {
        return $this->workflow->customerAccountAvailable($request, $customerAccount);
    }

    public function ledgerAccountMovements(Request $request, LedgerAccount $ledgerAccount): JsonResponse
    {
        return $this->workflow->ledgerAccountMovements($request, $ledgerAccount);
    }

    public function customerAccountStatement(Request $request, CustomerAccount $customerAccount): JsonResponse
    {
        return $this->workflow->customerAccountStatement($request, $customerAccount);
    }
}
