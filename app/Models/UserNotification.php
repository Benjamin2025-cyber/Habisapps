<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $recipient_type
 * @property int|null $recipient_id
 * @property int|null $agency_id
 * @property string $type
 * @property string $category
 * @property string $title
 * @property string $message
 * @property string|null $action_url
 * @property string $source_type
 * @property string $source_public_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'public_id',
    'recipient_type',
    'recipient_id',
    'agency_id',
    'type',
    'category',
    'title',
    'message',
    'action_url',
    'source_type',
    'source_public_id',
    'metadata',
])]
final class UserNotification extends Model
{
    use HasUlids;

    public const string RECIPIENT_USER = 'user';

    public const string RECIPIENT_AGENCY = 'agency';

    public const string RECIPIENT_PLATFORM = 'platform';

    public const string TYPE_INFO = 'info';

    public const string TYPE_SUCCESS = 'success';

    public const string TYPE_WARNING = 'warning';

    public const string TYPE_ERROR = 'error';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
