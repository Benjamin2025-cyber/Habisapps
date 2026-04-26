<?php

declare(strict_types=1);

namespace App\Support\Finance;

enum LedgerLineType: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
