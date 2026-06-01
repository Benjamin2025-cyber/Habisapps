<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\CashOperations\TellerTransactionWorkflow;
use App\Application\CashOperations\TellerTransactionWorkflowControllerAdapter;
use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreCashDepositRequest;
use App\Http\Requests\StoreCashManualJournalRequest;
use App\Http\Requests\StoreCashWithdrawalRequest;
use App\Http\Resources\TellerTransactionCollection;
use App\Models\TellerSession;
use App\Models\TellerTransaction;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TellerTransactionController extends BaseController
{
    public function __construct(
        private readonly TellerTransactionWorkflowControllerAdapter $teller,
        private readonly TellerTransactionWorkflow $list,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{teller_transactions: array<int, \App\Http\Resources\TellerTransactionResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): TellerTransactionCollection|JsonResponse
    {
        return $this->list->index($request);
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{teller_transaction: \App\Http\Resources\TellerTransactionResource, journal_entry: \App\Http\Resources\JournalEntryResource}, errors: null, meta: null}')]
    public function storeDeposit(StoreCashDepositRequest $request, TellerSession $tellerSession): JsonResponse
    {
        return $this->teller->storeDeposit($request, $tellerSession);
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{teller_transaction: \App\Http\Resources\TellerTransactionResource, journal_entry: \App\Http\Resources\JournalEntryResource}, errors: null, meta: null}')]
    public function storeWithdrawal(StoreCashWithdrawalRequest $request, TellerSession $tellerSession): JsonResponse
    {
        return $this->teller->storeWithdrawal($request, $tellerSession);
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{teller_transaction: \App\Http\Resources\TellerTransactionResource, journal_entry: \App\Http\Resources\JournalEntryResource}, errors: null, meta: null}')]
    public function reverse(Request $request, TellerTransaction $tellerTransaction): JsonResponse
    {
        return $this->teller->reverse($request, $tellerTransaction);
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{teller_transaction: \App\Http\Resources\TellerTransactionResource, journal_entry: \App\Http\Resources\JournalEntryResource}, errors: null, meta: null}')]
    public function storeManualJournal(StoreCashManualJournalRequest $request, TellerSession $tellerSession): JsonResponse
    {
        return $this->teller->storeManualJournal($request, $tellerSession);
    }
}
