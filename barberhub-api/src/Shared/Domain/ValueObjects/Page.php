<?php

declare(strict_types=1);

namespace Src\Shared\Domain\ValueObjects;

/**
 * One page of a listing and the size of the whole listing.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * Keep the elements of the page, the total of the listing and the request that produced it.
     *
     * @param  list<T>  $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public PageRequest $request,
    ) {}

    /** Number of the last page; an empty listing still has page 1. */
    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->request->size));
    }

    /**
     * Turn each element into another value, keeping the page.
     *
     * @template U
     *
     * @param  callable(T): U  $map
     * @return Page<U>
     */
    public function map(callable $map): self
    {
        return new self(array_map($map, $this->items), $this->total, $this->request);
    }
}
