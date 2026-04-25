<?php

declare(strict_types=1);

namespace App\Support\Traits;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @mixin Model
 */
trait HasAuditLog
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName($this->getAuditLogName());
    }

    protected function getAuditLogName(): string
    {
        return strtolower(class_basename($this));
    }
}
