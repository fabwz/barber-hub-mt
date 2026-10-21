<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\DTOs;

use Src\Availability\Slots\Domain\ValueObjects\Slot;

final readonly class StaffSlots
{
    /**
     * Carry the free spaces of one collaborator on a day, in order of start.
     *
     * @param  list<Slot>  $slots
     */
    public function __construct(
        public string $collaboratorId,
        public string $name,
        public array $slots,
    ) {}
}
