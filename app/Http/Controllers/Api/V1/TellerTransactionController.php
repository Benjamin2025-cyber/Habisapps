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
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TellerTransactionController extends BaseController
{
    public function __construct(
        private readonly TellerTransactionWorkflowControllerAdapter $teller,
        private readonly TellerTransactionWorkflow $list,
    ) {}

    #[QueryParameter('filter[teller_session_public_id]', 'Limit results to a teller session public ID.', type: 'string')]
    #[QueryParameter('filter[till_public_id]', 'Limit results to a till public ID.', type: 'string')]
    #[QueryParameter('filter[teller_user_public_id]', 'Limit results to a teller user public ID.', type: 'string')]
    #[QueryParameter('filter[transaction_type]', 'Limit results to a transaction type such as cash_deposit, cash_withdrawal, cash_manual_journal, or cash_reversal.', type: 'string')]
    #[QueryParameter('filter[status]', 'Limit results to a transaction status such as posted, pending_review, reversed, or cancelled.', type: 'string')]
    #[QueryParameter('filter[transaction_date]', 'Limit results to an exact transaction date in YYYY-MM-DD format.', type: 'string', format: 'date')]
    #[QueryParameter('filter[transaction_date_from]', 'Limit results to transactions on or after this date in YYYY-MM-DD format.', type: 'string', format: 'date')]
    #[QueryParameter('filter[transaction_date_to]', 'Limit results to transactions on or before this date in YYYY-MM-DD format.', type: 'string', format: 'date')]
    #[QueryParameter('filter[customer_account_public_id]', 'Limit results to a customer account public ID.', type: 'string')]
    #[QueryParameter('filter[loan_public_id]', 'Limit results to a loan public ID.', type: 'string')]
    #[QueryParameter('search', 'Search reference, event number, operation code, depositor name, and description.', type: 'string')]
    #[QueryParameter('per_page', 'Results per page. Capped at 100.', type: 'integer')]
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
