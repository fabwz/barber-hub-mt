<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\UseCases;

use Src\Catalog\Barbershops\Application\Contracts\PublicBarbershopReader;
use Src\Catalog\Barbershops\Application\DTOs\PublicBarbershop;
use Src\Catalog\Barbershops\Domain\Exceptions\BarbershopNotFoundException;
use Src\Catalog\Barbershops\Domain\ValueObjects\PublicLink;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final readonly class GetBarbershopByLink
{
    /** Receive the port that reads barbershops by their link. */
    public function __construct(private PublicBarbershopReader $reader) {}

    /** Return the public data of the barbershop a link opens; a malformed link opens none, so it is not queried (RF-02). */
    public function execute(string $link): PublicBarbershop
    {
        try {
            $link = new PublicLink($link);
        } catch (InvalidValueException) {
            throw BarbershopNotFoundException::create();
        }

        return $this->reader->findByLink($link) ?? throw BarbershopNotFoundException::create();
    }
}
