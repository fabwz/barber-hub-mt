<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Application\DTOs;

/** What one run of the daily blocking did: how many barbershops it blocked and the ones it could not process. */
final readonly class RentBlockingResult
{
    /**
     * @param  int  $blocked  Barbershops blocked in this run; one that was already blocked does not count.
     * @param  list<string>  $failedBarbershopIds  Barbershops whose check failed; the others were processed anyway.
     */
    public function __construct(
        public int $blocked,
        public array $failedBarbershopIds,
    ) {}
}
