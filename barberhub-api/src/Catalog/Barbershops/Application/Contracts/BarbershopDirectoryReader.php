<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\Contracts;

use Src\Catalog\Barbershops\Application\DTOs\DirectoryBarbershop;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;

interface BarbershopDirectoryReader
{
    /**
     * Read one page of the barbershops that appear in the public directory, by name. A search keeps those whose name or city
     * contains it, ignoring case and accents.
     *
     * @return Page<DirectoryBarbershop>
     */
    public function page(?string $search, PageRequest $request): Page;
}
