<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Infrastructure\Http\Resources;

use DateTimeImmutable;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Availability\Slots\Application\DTOs\Availability;
use Src\Availability\Slots\Application\DTOs\DayAvailability;
use Src\Availability\Slots\Application\DTOs\StaffSlots;
use Src\Availability\Slots\Domain\ValueObjects\CalendarDate;
use Src\Availability\Slots\Domain\ValueObjects\Slot;

/**
 * @property-read Availability $resource
 */
#[SchemaName('Disponibilidad')]
final class AvailabilityResource extends JsonResource
{
    /** Wrap the answer of the availability. */
    public function __construct(private readonly Availability $availability)
    {
        parent::__construct($availability);
    }

    /**
     * Expose the service with its price, the spaces of the day grouped by barber and, if the day has none, the next days that do (RF-10).
     *
     * @return array{servicio: array{id: string, nombre: string, duracion_minutos: int, precio: int, base: int, iva: int}, fecha: string, barberos: list<array<string, mixed>>, dias_siguientes: list<array<string, mixed>>}
     */
    public function toArray(Request $request): array
    {
        $service = $this->availability->service;

        return [
            'servicio' => [
                'id' => $service->id,
                'nombre' => $service->name,
                'duracion_minutos' => $service->duration->minutes,
                /** Colones con el IVA incluido; es igual a `base` más `iva` (RL-02). */
                'precio' => $service->price,
                'base' => $service->base,
                'iva' => $service->tax,
            ],
            /** @format date */
            'fecha' => $this->availability->day->date,
            /** Espacios del día, agrupados por barbero; un barbero sin espacios no aparece. */
            'barberos' => $this->groups($this->availability->day),
            /** Si el día no tiene espacios, hasta 3 días siguientes que sí los tienen; si no, una lista vacía. */
            'dias_siguientes' => array_map(
                fn (DayAvailability $day): array => ['fecha' => $day->date, 'barberos' => $this->groups($day)],
                $this->availability->nextDays,
            ),
        ];
    }

    /**
     * The barbers of a day with their spaces.
     *
     * @return list<array{id: string, nombre: string, espacios: list<array{inicio: string, fin: string, barbero: string}>}>
     */
    private function groups(DayAvailability $day): array
    {
        return array_map(fn (StaffSlots $staff): array => [
            'id' => $staff->collaboratorId,
            'nombre' => $staff->name,
            'espacios' => array_map(fn (Slot $slot): array => [
                /** Inicio del servicio, fecha y hora ISO 8601 con el desfase de Costa Rica (-06:00). */
                'inicio' => $this->moment($slot->start),
                /** Fin del servicio, sin la limpieza que le sigue; mismo formato que `inicio`. */
                'fin' => $this->moment($slot->end),
                /** Identificador del barbero del espacio, el que se envía al reservar. */
                'barbero' => $slot->collaboratorId->value,
            ], $staff->slots),
        ], $day->staff);
    }

    /** Write an instant as ISO 8601 in Costa Rica time. */
    private function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone(CalendarDate::ZONE))->format(DATE_ATOM);
    }
}
