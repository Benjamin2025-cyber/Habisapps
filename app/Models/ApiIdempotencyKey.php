<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $scope_hash
 * @property string $request_fingerprint
 * @property array<array-key, mixed>|null $response_body
 * @property int|null $response_status
 * @property array<string, string>|null $response_headers
 */
#[Fillable([
    'key',
    'method',
    'path',
    'actor_context',
    'scope_hash',
    'request_fingerprint',
    'response_body',
    'response_status',
    'response_headers',
    'completed_at',
    'expires_at',
])]
final class ApiIdempotencyKey extends Model
{
    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_headers' => 'array',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
