<?php

declare(strict_types=1);

namespace App\Application\CashOperations;

use App\Http\Requests\StoreCashDepositRequest;
use App\Http\Requests\StoreCashManualJournalRequest;
use App\Http\Requests\StoreCashWithdrawalRequest;
use App\Models\TellerSession;
use App\Models\TellerTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TellerTransactionWorkflowControllerAdapter
{
    public function __construct(
        private readonly TellerCashTransactionWorkflow $cash,
        private readonly TellerManualJournalWorkflow $manualJournal,
    ) {}

    public function storeDeposit(StoreCashDepositRequest $request, TellerSession $tellerSession): JsonResponse
    {
        return $this->cash->storeDeposit($request, $tellerSession);
    }

    public function storeWithdrawal(StoreCashWithdrawalRequest $request, TellerSession $tellerSession): JsonResponse
    {
        return $this->cash->storeWithdrawal($request, $tellerSession);
    }

    public function reverse(Request $request, TellerTransaction $tellerTransaction): JsonResponse
    {
        return $this->cash->reverse($request, $tellerTransaction);
    }

    public function storeManualJournal(StoreCashManualJournalRequest $request, TellerSession $tellerSession): JsonResponse
    {
        return $this->manualJournal->storeManualJournal($request, $tellerSession);
    }
}
