<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Catalog\OpeningHours\Domain\Entities\TimeSlot;

/**
 * @property-read TimeSlot $resource
 */
#[SchemaName('Franja')]
final class TimeSlotResource extends JsonResource
{
    /** Wrap a slot of the schedule. */
    public function __construct(private readonly TimeSlot $slot)
    {
        parent::__construct($slot);
    }

    /**
     * Expose the slot with the vocabulary of the business.
     *
     * @return array{id: string, dia: int, abre: string, cierra: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->slot->id->value,
            'dia' => $this->slot->day->value,
            'abre' => $this->slot->range->start->value,
            'cierra' => $this->slot->range->end->value,
        ];
    }
}
