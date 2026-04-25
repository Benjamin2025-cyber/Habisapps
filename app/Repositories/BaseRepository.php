<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @template TModel of Model
 */
abstract class BaseRepository
{
    /** @var class-string<TModel> */
    protected string $modelClass;

    /** @return Builder<TModel> */
    protected function newQuery(): Builder
    {
        return $this->modelClass::query();
    }

    /** @return QueryBuilder<TModel> */
    protected function newQueryBuilder(): QueryBuilder
    {
        return QueryBuilder::for($this->modelClass);
    }

    /**
     * @param array<int|string> $ids
     * @return Collection<int, TModel>
     */
    public function findMany(array $ids): Collection
    {
        return $this->newQuery()->whereKey($ids)->get();
    }

    /**
     * @param array<int|string> $ids
     * @return Collection<int, TModel>
     */
    public function findManyOrFail(array $ids): Collection
    {
        $result = $this->newQuery()->whereKey($ids)->get();

        if ($result->isEmpty()) {
            throw (new ModelNotFoundException())->setModel($this->modelClass, $ids);
        }

        return $result;
    }

    /** @return TModel|null */
    public function findById(string|int $id): ?Model
    {
        return $this->newQuery()->find($id);
    }

    /** @return TModel */
    public function findByIdOrFail(string|int $id): Model
    {
        return $this->newQuery()->findOrFail($id);
    }

    /** @return TModel|null */
    public function findByField(string $field, mixed $value): ?Model
    {
        return $this->newQuery()->where($field, $value)->first();
    }

    /** @return TModel */
    public function findFirstByFieldOrFail(string $field, mixed $value): Model
    {
        return $this->newQuery()->where($field, $value)->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Model
    {
        /** @var TModel */
        return $this->newQuery()->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function update(Model $model, array $attributes): Model
    {
        /** @var TModel */
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
        return $this->newQuery()->where($field, $value)->exists();
    }
}
