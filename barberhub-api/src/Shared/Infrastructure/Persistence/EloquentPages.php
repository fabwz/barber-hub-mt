<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;

final class EloquentPages
{
    /**
     * Read one page of a query with two statements, the count and the rows, and turn each model into a domain object.
     *
     * @template TModel of Model
     * @template T
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): T  $toDomain
     * @return Page<T>
     */
    public static function of(Builder $query, PageRequest $request, callable $toDomain): Page
    {
        $total = $query->toBase()->getCountForPagination();
        $models = $total > $request->offset()
            ? $query->forPage($request->number, $request->size)->get()->all()
            : [];

        return new Page(array_values(array_map($toDomain, $models)), $total, $request);
    }
}
