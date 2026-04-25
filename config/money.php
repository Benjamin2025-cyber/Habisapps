<?php

declare(strict_types=1);

return [
    'default_currency' => env('MONEY_DEFAULT_CURRENCY', 'TZS'),
    'default_scale' => (int) env('MONEY_DEFAULT_SCALE', 2),
];
