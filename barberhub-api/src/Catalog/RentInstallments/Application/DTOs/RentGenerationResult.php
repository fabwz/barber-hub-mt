<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Application\DTOs;

/** What one run of the daily generation did: how many installments it created and the barbershops it could not process. */
final readonly class RentGenerationResult
{
    /**
     * @param  int  $created  Installments created in this run.
     * @param  list<string>  $failedBarbershopIds  Barbershops whose generation failed; the others were processed anyway.
     */
    public function __construct(
        public int $created,
        public array $failedBarbershopIds,
    ) {}
}
