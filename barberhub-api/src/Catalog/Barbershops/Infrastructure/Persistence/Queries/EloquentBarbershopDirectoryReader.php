<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Persistence\Queries;

use Illuminate\Database\Eloquent\Builder;
use Src\Catalog\Barbershops\Application\Contracts\BarbershopDirectoryReader;
use Src\Catalog\Barbershops\Application\DTOs\DirectoryBarbershop;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Models\BarbershopModel;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Shared\Infrastructure\Persistence\EloquentPages;

/**
 * Reads the directory straight from `barbershops`: that table holds the barbershops themselves, has no row-level security and is
 * the same one the public link reads (MT-07). The query selects only the public columns, so no contact data is even loaded.
 */
final readonly class EloquentBarbershopDirectoryReader implements BarbershopDirectoryReader
{
    /** Letters with accents, and the plain letters they fold into for the search. */
    private const string ACCENTED = 'ÁÉÍÓÚÜÑáéíóúüñ';

    private const string PLAIN = 'AEIOUUNaeiouun';

    /** Read one page of the listed barbershops with two statements, the count and the rows. */
    public function page(?string $search, PageRequest $request): Page
    {
        $query = BarbershopModel::query()
            ->select(['id', 'name', 'city', 'public_link'])
            ->where('listed_in_directory', true)
            ->orderBy('name')
            ->orderBy('id');

        if ($search !== null) {
            $this->applySearch($query, $search);
        }

        // The address of a logo carries the identifier of the barbershop, which the directory never exposes (MT-09): it shows null until that is decided.
        return EloquentPages::of($query, $request, fn (BarbershopModel $barbershop): DirectoryBarbershop => new DirectoryBarbershop(
            $barbershop->name,
            $barbershop->city,
            null,
            $barbershop->public_link,
        ));
    }

    /**
     * Keep the barbershops whose name or city contains the text, ignoring case and accents on both sides. The text goes as a
     * binding and its wildcards are escaped, so a `%` or an `_` only matches itself.
     *
     * @param  Builder<BarbershopModel>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        $folded = strtr(mb_strtolower($search), array_combine(
            mb_str_split(mb_strtolower(self::ACCENTED)),
            mb_str_split(mb_strtolower(self::PLAIN)),
        ));
        $pattern = '%'.addcslashes($folded, '\\%_').'%';
        $fold = "lower(translate(%s, '".self::ACCENTED."', '".self::PLAIN."')) like ? escape '\\'";

        $query->where(function (Builder $where) use ($fold, $pattern): void {
            $where->whereRaw(sprintf($fold, 'name'), [$pattern])->orWhereRaw(sprintf($fold, 'city'), [$pattern]);
        });
    }
}
