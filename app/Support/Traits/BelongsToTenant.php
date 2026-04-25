<?php

declare(strict_types=1);

namespace App\Support\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Context;
use RuntimeException;

/**
 * @mixin Model
 *
 * @property string $tenant_id
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', static function (Builder $builder): void {
            $tenantId = Context::get('tenant_id');

            if ($tenantId === null) {
                return;
            }

            $builder->where($builder->getModel()->getTable() . '.tenant_id', $tenantId);
        });

        static::creating(static function (Model $model): void {
            $tenantId = Context::get('tenant_id');

            if ($tenantId === null) {
                throw new RuntimeException('Tenant context must be set before creating a tenant-scoped model.');
            }

            if (empty($model->getAttribute('tenant_id'))) {
                $model->setAttribute('tenant_id', $tenantId);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        /** @var class-string<Model> $tenantModel */
        $tenantModel = config('multitenancy.tenant_model', \App\Models\Tenant::class);

        return $this->belongsTo($tenantModel);
    }
}
