<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountHoldController;
use App\Http\Controllers\Api\V1\CustomerAccountController;
use App\Http\Controllers\Api\V1\DenominationController;
use App\Http\Controllers\Api\V1\JournalEntryController;
use App\Http\Controllers\Api\V1\JournalLineController;
use App\Http\Controllers\Api\V1\LedgerAccountController;
use App\Http\Controllers\Api\V1\SectorController;
use App\Http\Controllers\Api\V1\SubSectorController;
use App\Http\Controllers\Api\V1\TillController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('ledger-accounts', [LedgerAccountController::class, 'index']);
    Route::post('ledger-accounts', [LedgerAccountController::class, 'store']);
    Route::get('ledger-accounts/{ledgerAccount}', [LedgerAccountController::class, 'show']);
    Route::patch('ledger-accounts/{ledgerAccount}', [LedgerAccountController::class, 'update']);
    Route::delete('ledger-accounts/{ledgerAccount}', [LedgerAccountController::class, 'destroy']);

    Route::get('customer-accounts', [CustomerAccountController::class, 'index']);
    Route::post('customer-accounts', [CustomerAccountController::class, 'store']);
    Route::get('customer-accounts/{customerAccount}', [CustomerAccountController::class, 'show']);
    Route::patch('customer-accounts/{customerAccount}', [CustomerAccountController::class, 'update']);
    Route::delete('customer-accounts/{customerAccount}', [CustomerAccountController::class, 'destroy']);

    Route::get('account-holds', [AccountHoldController::class, 'index']);
    Route::post('account-holds', [AccountHoldController::class, 'store']);
    Route::get('account-holds/{accountHold}', [AccountHoldController::class, 'show']);
    Route::patch('account-holds/{accountHold}', [AccountHoldController::class, 'update']);
    Route::post('account-holds/{accountHold}/release', [AccountHoldController::class, 'release']);
    Route::delete('account-holds/{accountHold}', [AccountHoldController::class, 'destroy']);

    Route::get('journal-entries', [JournalEntryController::class, 'index']);
    Route::post('journal-entries', [JournalEntryController::class, 'store'])->middleware('throttle:journal.write');
    Route::get('journal-entries/{journalEntry}', [JournalEntryController::class, 'show']);
    Route::patch('journal-entries/{journalEntry}', [JournalEntryController::class, 'update']);
    Route::post('journal-entries/{journalEntry}/submit', [JournalEntryController::class, 'submit'])->middleware('throttle:journal.write');
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
});
