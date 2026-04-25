<?php

declare(strict_types=1);

namespace App\Support\Traits;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use LogicException;

trait HasUuid
{
    use HasUuids {
        bootHasUuids as bootHasUuidsParent;
    }

    public static function bootHasUuid(): void
    {
        static::bootHasUuidsParent();

        static::creating(static function ($model): void {
            $keyName = $model->getKeyName();

            if ($keyName !== 'id') {
                throw new LogicException(
                    sprintf('HasUuid trait requires `id` as primary key, got `%s`.', $keyName)
                );
            }
        });
    }
}
