<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use DateTimeImmutable;
use Src\Reservations\Reservations\Domain\ValueObjects\CancellationClass;
use Src\Reservations\Reservations\Domain\ValueObjects\EconomicConsequence;

final readonly class CancellationPreview
{
    /** Carry what would happen if the customer cancelled now: the class, the last instant without penalty and the consequence. */
    public function __construct(
        public CancellationClass $class,
        public DateTimeImmutable $limit,
        public EconomicConsequence $consequence,
    ) {}
}
