<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\UseCases;

use Src\Catalog\Barbershops\Application\Contracts\BarbershopDirectoryReader;
use Src\Catalog\Barbershops\Application\DTOs\DirectoryBarbershop;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;

final readonly class ListDirectoryBarbershops
{
    /** Receive the port that reads the directory. */
    public function __construct(private BarbershopDirectoryReader $reader) {}

    /**
     * List one page of the barbershops whose owner turned on "appear in the directory" (RF-54, MT-10), ordered by name. It reads
     * across barbershops by design and without a session, so the port gives back only the public fields. A blank search is no search.
     *
     * @return Page<DirectoryBarbershop>
     */
    public function execute(?string $search, PageRequest $request): Page
    {
        $search = $search === null ? null : trim($search);

        return $this->reader->page($search === '' ? null : $search, $request);
    }
}
