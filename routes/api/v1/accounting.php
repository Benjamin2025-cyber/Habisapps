<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountHoldController;
use App\Http\Controllers\Api\V1\AccountingBalanceController;
use App\Http\Controllers\Api\V1\AccountingDayController;
use App\Http\Controllers\Api\V1\AccountProductController;
use App\Http\Controllers\Api\V1\CustomerAccountController;
use App\Http\Controllers\Api\V1\CustomerAccountSignatureController;
use App\Http\Controllers\Api\V1\DenominationController;
use App\Http\Controllers\Api\V1\EmfLedgerAccountMappingController;
use App\Http\Controllers\Api\V1\EmfRegulatoryAccountController;
use App\Http\Controllers\Api\V1\JournalEntryController;
use App\Http\Controllers\Api\V1\JournalLineController;
use App\Http\Controllers\Api\V1\LedgerAccountController;
use App\Http\Controllers\Api\V1\OperationAccountMappingController;
use App\Http\Controllers\Api\V1\OperationCodeController;
use App\Http\Controllers\Api\V1\ReportRunController;
use App\Http\Controllers\Api\V1\SectorController;
use App\Http\Controllers\Api\V1\SubSectorController;
use App\Http\Controllers\Api\V1\TellerSessionController;
use App\Http\Controllers\Api\V1\TellerTransactionController;
use App\Http\Controllers\Api\V1\TillController;
use App\Http\Controllers\Api\V1\TillReconciliationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'accounting.day.registration-lock'])->group(function (): void {
    Route::get('accounting-days', [AccountingDayController::class, 'index']);
    Route::get('accounting-days/current', [AccountingDayController::class, 'current']);
    Route::post('accounting-days/open', [AccountingDayController::class, 'open'])
        ->middleware('throttle:accounting.lifecycle')
        ->defaults('accounting_day_classification', 'day_lifecycle');
    Route::get('accounting-days/{accountingDay}', [AccountingDayController::class, 'show']);
    Route::post('accounting-days/{accountingDay}/start-close', [AccountingDayController::class, 'startClose'])
        ->middleware('throttle:accounting.lifecycle')
        ->defaults('accounting_day_classification', 'day_lifecycle');
    Route::post('accounting-days/{accountingDay}/close', [AccountingDayController::class, 'close'])
        ->middleware('throttle:accounting.lifecycle')
        ->defaults('accounting_day_classification', 'day_lifecycle');
    Route::post('accounting-days/{accountingDay}/reopen', [AccountingDayController::class, 'reopen'])
        ->middleware('throttle:accounting.lifecycle')
        ->defaults('accounting_day_classification', 'day_lifecycle');

    Route::get('account-products', [AccountProductController::class, 'index']);
    Route::post('account-products', [AccountProductController::class, 'store']);
    Route::get('account-products/{accountProduct}', [AccountProductController::class, 'show']);
    Route::patch('account-products/{accountProduct}', [AccountProductController::class, 'update']);
    Route::delete('account-products/{accountProduct}', [AccountProductController::class, 'destroy']);

    Route::get('emf-regulatory-accounts', [EmfRegulatoryAccountController::class, 'index']);
    Route::post('emf-regulatory-accounts', [EmfRegulatoryAccountController::class, 'store']);
    Route::get('emf-regulatory-accounts/{emfRegulatoryAccount}', [EmfRegulatoryAccountController::class, 'show']);
    Route::patch('emf-regulatory-accounts/{emfRegulatoryAccount}', [EmfRegulatoryAccountController::class, 'update']);
    Route::delete('emf-regulatory-accounts/{emfRegulatoryAccount}', [EmfRegulatoryAccountController::class, 'destroy']);

    Route::get('emf-ledger-account-mappings', [EmfLedgerAccountMappingController::class, 'index']);
    Route::post('emf-ledger-account-mappings', [EmfLedgerAccountMappingController::class, 'store']);
    Route::get('emf-ledger-account-mappings/{emfLedgerAccountMapping}', [EmfLedgerAccountMappingController::class, 'show']);
    Route::patch('emf-ledger-account-mappings/{emfLedgerAccountMapping}', [EmfLedgerAccountMappingController::class, 'update']);
    Route::delete('emf-ledger-account-mappings/{emfLedgerAccountMapping}', [EmfLedgerAccountMappingController::class, 'destroy']);

    Route::get('operation-codes', [OperationCodeController::class, 'index']);
    Route::post('operation-codes', [OperationCodeController::class, 'store']);
    Route::get('operation-codes/{operationCode}', [OperationCodeController::class, 'show']);
    Route::patch('operation-codes/{operationCode}', [OperationCodeController::class, 'update']);
    Route::delete('operation-codes/{operationCode}', [OperationCodeController::class, 'destroy']);

    Route::get('operation-account-mappings', [OperationAccountMappingController::class, 'index']);
    Route::get('operation-account-mappings/readiness', [OperationAccountMappingController::class, 'readiness']);
    Route::post('operation-account-mappings', [OperationAccountMappingController::class, 'store']);
    Route::get('operation-account-mappings/{operationAccountMapping}', [OperationAccountMappingController::class, 'show']);
    Route::patch('operation-account-mappings/{operationAccountMapping}', [OperationAccountMappingController::class, 'update']);
    Route::delete('operation-account-mappings/{operationAccountMapping}', [OperationAccountMappingController::class, 'destroy']);

    Route::get('report-runs', [ReportRunController::class, 'index']);
    Route::post('report-runs', [ReportRunController::class, 'store']);
    Route::get('report-runs/{reportRun}', [ReportRunController::class, 'show']);

    Route::get('ledger-accounts', [LedgerAccountController::class, 'index']);
    Route::post('ledger-accounts', [LedgerAccountController::class, 'store']);
    Route::get('ledger-accounts/{ledgerAccount}', [LedgerAccountController::class, 'show']);
    Route::get('ledger-accounts/{ledgerAccount}/balance', [AccountingBalanceController::class, 'ledgerAccount']);
    Route::get('ledger-accounts/{ledgerAccount}/movements', [AccountingBalanceController::class, 'ledgerAccountMovements']);
    Route::patch('ledger-accounts/{ledgerAccount}', [LedgerAccountController::class, 'update']);
    Route::delete('ledger-accounts/{ledgerAccount}', [LedgerAccountController::class, 'destroy']);

    Route::get('customer-accounts', [CustomerAccountController::class, 'index']);
    Route::post('customer-accounts', [CustomerAccountController::class, 'store']);
    Route::get('customer-accounts/{customerAccount}/signatures', [CustomerAccountSignatureController::class, 'index']);
    Route::post('customer-accounts/{customerAccount}/signatures', [CustomerAccountSignatureController::class, 'store']);
    Route::get('customer-accounts/{customerAccount}/signatures/{signature}', [CustomerAccountSignatureController::class, 'show']);
    Route::post('customer-accounts/{customerAccount}/signatures/{signature}/verify', [CustomerAccountSignatureController::class, 'verify']);
    Route::post('customer-accounts/{customerAccount}/signatures/{signature}/revoke', [CustomerAccountSignatureController::class, 'revoke']);
    Route::get('customer-accounts/{customerAccount}', [CustomerAccountController::class, 'show']);
    Route::get('customer-accounts/{customerAccount}/balance', [AccountingBalanceController::class, 'customerAccount']);
    Route::get('customer-accounts/{customerAccount}/available-balance', [AccountingBalanceController::class, 'customerAccountAvailable']);
    Route::get('customer-accounts/{customerAccount}/statement', [AccountingBalanceController::class, 'customerAccountStatement']);
    Route::patch('customer-accounts/{customerAccount}', [CustomerAccountController::class, 'update']);
    Route::delete('customer-accounts/{customerAccount}', [CustomerAccountController::class, 'destroy']);

    Route::get('account-holds', [AccountHoldController::class, 'index']);
    Route::post('account-holds', [AccountHoldController::class, 'store']);
    Route::get('account-holds/{accountHold}', [AccountHoldController::class, 'show']);
    Route::patch('account-holds/{accountHold}', [AccountHoldController::class, 'update']);
    Route::post('account-holds/{accountHold}/release', [AccountHoldController::class, 'release']);
    Route::delete('account-holds/{accountHold}', [AccountHoldController::class, 'destroy']);

    Route::get('journal-entries/stats', [JournalEntryController::class, 'stats']);
    Route::get('journal-entries', [JournalEntryController::class, 'index']);
    Route::post('journal-entries', [JournalEntryController::class, 'store'])->middleware('throttle:journal.write');
    Route::get('journal-entries/{journalEntry}', [JournalEntryController::class, 'show']);
    Route::patch('journal-entries/{journalEntry}', [JournalEntryController::class, 'update']);
    Route::post('journal-entries/{journalEntry}/submit', [JournalEntryController::class, 'submit'])->middleware('throttle:journal.write');
    Route::post('journal-entries/{journalEntry}/approve', [JournalEntryController::class, 'approve'])->middleware('throttle:journal.write');
    Route::post('journal-entries/{journalEntry}/reject', [JournalEntryController::class, 'reject'])->middleware('throttle:journal.write');
    Route::post('journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post'])->middleware('throttle:journal.write');
    Route::post('journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])->middleware('throttle:journal.write');
    Route::delete('journal-entries/{journalEntry}', [JournalEntryController::class, 'destroy']);

    Route::get('journal-lines', [JournalLineController::class, 'index']);
    Route::post('journal-lines', [JournalLineController::class, 'store'])->middleware('throttle:journal.write');
    Route::get('journal-lines/{journalLine}', [JournalLineController::class, 'show']);
    Route::patch('journal-lines/{journalLine}', [JournalLineController::class, 'update']);
    Route::delete('journal-lines/{journalLine}', [JournalLineController::class, 'destroy']);

    Route::get('sectors', [SectorController::class, 'index']);
    Route::post('sectors', [SectorController::class, 'store']);
    Route::get('sectors/{sector}', [SectorController::class, 'show']);
    Route::patch('sectors/{sector}', [SectorController::class, 'update']);
    Route::delete('sectors/{sector}', [SectorController::class, 'destroy']);

    Route::get('sub-sectors', [SubSectorController::class, 'index']);
    Route::post('sub-sectors', [SubSectorController::class, 'store']);
    Route::get('sub-sectors/{subSector}', [SubSectorController::class, 'show']);
    Route::patch('sub-sectors/{subSector}', [SubSectorController::class, 'update']);
    Route::delete('sub-sectors/{subSector}', [SubSectorController::class, 'destroy']);

    Route::get('denominations', [DenominationController::class, 'index']);
    Route::post('denominations', [DenominationController::class, 'store']);
    Route::get('denominations/{denomination}', [DenominationController::class, 'show']);
    Route::patch('denominations/{denomination}', [DenominationController::class, 'update']);

    Route::get('tills', [TillController::class, 'index']);
    Route::post('tills', [TillController::class, 'store']);
    Route::get('tills/{till}', [TillController::class, 'show']);
    Route::patch('tills/{till}', [TillController::class, 'update']);

    Route::get('teller-sessions', [TellerSessionController::class, 'index']);
    Route::post('teller-sessions', [TellerSessionController::class, 'store']);
    Route::get('teller-sessions/{tellerSession}', [TellerSessionController::class, 'show']);
    Route::post('teller-sessions/{tellerSession}/close', [TellerSessionController::class, 'close']);
    Route::post('teller-sessions/{tellerSession}/deposits', [TellerTransactionController::class, 'storeDeposit']);
    Route::post('teller-sessions/{tellerSession}/withdrawals', [TellerTransactionController::class, 'storeWithdrawal']);
    Route::post('teller-sessions/{tellerSession}/manual-journal-entries', [TellerTransactionController::class, 'storeManualJournal']);
    Route::get('teller-sessions/{tellerSession}/reconciliations', [TillReconciliationController::class, 'index']);
    Route::post('teller-sessions/{tellerSession}/reconciliations', [TillReconciliationController::class, 'store']);
    Route::get('teller-transactions', [TellerTransactionController::class, 'index']);
    Route::post('teller-transactions/{tellerTransaction}/reverse', [TellerTransactionController::class, 'reverse']);
    Route::get('till-reconciliations', [TillReconciliationController::class, 'index']);
});
