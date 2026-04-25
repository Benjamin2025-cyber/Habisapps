<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\QueryBuilder;

abstract class BaseRepository
{
    /** @var class-string<Model> */
    protected string $modelClass;

    /** @return Builder<Model> */
    protected function newQuery(): Builder
    {
        return $this->newModel()->newQuery();
    }

    protected function newModel(): Model
    {
        return new $this->modelClass;
    }

    /** @return QueryBuilder<Model> */
    protected function newQueryBuilder(): QueryBuilder
    {
        return QueryBuilder::for($this->modelClass);
    }

    /**
     * @param  array<int|string>  $ids
     * @return Collection<int, Model>
     */
    public function findMany(array $ids): Collection
    {
        return $this->newQuery()->whereKey($ids)->get();
    }

    /**
     * @param  array<int|string>  $ids
     * @return Collection<int, Model>
     */
    public function findManyOrFail(array $ids): Collection
    {
        $result = $this->newQuery()->whereKey($ids)->get();

        if ($result->isEmpty()) {
            throw (new ModelNotFoundException)->setModel($this->modelClass, $ids);
        }

        return $result;
    }

    public function findById(string|int $id): ?Model
    {
        return $this->newQuery()->find($id);
    }

    public function findByIdOrFail(string|int $id): Model
    {
        return $this->newQuery()->findOrFail($id);
    }

    public function findByField(string $field, mixed $value): ?Model
    {
        return $this->newQuery()->where($field, $value)->first();
    }

    public function findFirstByFieldOrFail(string $field, mixed $value): Model
    {
        return $this->newQuery()->where($field, $value)->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Model
    {
        return $this->newQuery()->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function update(Model $model, array $attributes): Model
    {
        return DB::transaction(function () use ($model, $attributes): Model {
            $model->update($attributes);

            return $model->refresh();
        });
    }

    public function delete(Model $model): bool
    {
        /** @var bool */
        return DB::transaction(fn (): bool => (bool) $model->delete());
    }

    public function hasRecordWith(string $field, mixed $value): bool
    {
        return $this->newQuery()->where($field, $value)->first() !== null;
    }
}
