<?php

namespace App\Repositories\HR;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Base Repository - Provides common database operations
 * All HR repositories should extend this class
 */
abstract class BaseRepository
{
    /**
     * @var Model
     */
    protected $model;

    /**
     * Get all records
     *
     * @param array $columns
     * @return Collection
     */
    public function all(array $columns = ['*']): Collection
    {
        return $this->model->select($columns)->get();
    }

    /**
     * Get all records with pagination
     *
     * @param int $perPage
     * @param array $columns
     * @return LengthAwarePaginator
     */
    public function paginate(int $perPage = 15, array $columns = ['*']): LengthAwarePaginator
    {
        return $this->model->select($columns)->paginate($perPage);
    }

    /**
     * Find record by ID
     *
     * @param int $id
     * @param array $columns
     * @return Model|null
     */
    public function find(int $id, array $columns = ['*']): ?Model
    {
        return $this->model->select($columns)->find($id);
    }

    /**
     * Find record by ID or fail
     *
     * @param int $id
     * @param array $columns
     * @return Model
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail(int $id, array $columns = ['*']): Model
    {
        return $this->model->select($columns)->findOrFail($id);
    }

    /**
     * Find record by column value
     *
     * @param string $column
     * @param mixed $value
     * @param array $columns
     * @return Model|null
     */
    public function findBy(string $column, $value, array $columns = ['*']): ?Model
    {
        return $this->model->select($columns)->where($column, $value)->first();
    }

    /**
     * Get all records matching column value
     *
     * @param string $column
     * @param mixed $value
     * @param array $columns
     * @return Collection
     */
    public function getAllBy(string $column, $value, array $columns = ['*']): Collection
    {
        return $this->model->select($columns)->where($column, $value)->get();
    }

    /**
     * Create a new record
     *
     * @param array $attributes
     * @return Model
     */
    public function create(array $attributes): Model
    {
        return $this->model->create($attributes);
    }

    /**
     * Update a record
     *
     * @param int $id
     * @param array $attributes
     * @return bool
     */
    public function update(int $id, array $attributes): bool
    {
        $record = $this->findOrFail($id);
        return $record->update($attributes);
    }

    /**
     * Delete a record
     *
     * @param int $id
     * @return bool|null
     * @throws \Exception
     */
    public function delete(int $id): ?bool
    {
        $record = $this->findOrFail($id);
        return $record->delete();
    }

    /**
     * Count records
     *
     * @param array $where
     * @return int
     */
    public function count(array $where = []): int
    {
        $query = $this->model->newQuery();

        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }

        return $query->count();
    }

    /**
     * Check if record exists
     *
     * @param array $where
     * @return bool
     */
    public function exists(array $where): bool
    {
        $query = $this->model->newQuery();

        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }

        return $query->exists();
    }

    /**
     * Get records with relationships
     *
     * @param array $relations
     * @param array $columns
     * @return Collection
     */
    public function with(array $relations, array $columns = ['*']): Collection
    {
        return $this->model->with($relations)->select($columns)->get();
    }

    /**
     * Get records with relationships and pagination
     *
     * @param array $relations
     * @param int $perPage
     * @param array $columns
     * @return LengthAwarePaginator
     */
    public function withPaginate(array $relations, int $perPage = 15, array $columns = ['*']): LengthAwarePaginator
    {
        return $this->model->with($relations)->select($columns)->paginate($perPage);
    }

    /**
     * Get fresh model instance
     *
     * @return Model
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Set model
     *
     * @param Model $model
     * @return $this
     */
    public function setModel(Model $model): self
    {
        $this->model = $model;
        return $this;
    }
}
