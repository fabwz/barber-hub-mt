<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use DateTimeImmutable;

/**
 * The only thing besides the phrase that travels to the language provider (IA-06): the names of the active services and
 * collaborators of the barbershop and today's date, whose time zone is the one of the barbershop (IA-04). Never an email,
 * a phone or anything of an account.
 */
final readonly class ExtractionContext
{
    /**
     * @param  list<string>  $serviceNames  Names of the active services.
     * @param  list<string>  $collaboratorNames  Names of the active collaborators.
     * @param  DateTimeImmutable  $today  The current instant in the time zone of the barbershop (America/Costa_Rica).
     */
    public function __construct(
        public array $serviceNames,
        public array $collaboratorNames,
        public DateTimeImmutable $today,
    ) {}
}
