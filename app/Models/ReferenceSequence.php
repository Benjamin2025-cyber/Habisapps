<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $prefix
 * @property int $padding
 * @property int $next_number
 */
#[Fillable([
    'key',
    'prefix',
    'padding',
    'next_number',
])]
final class ReferenceSequence extends Model {}
