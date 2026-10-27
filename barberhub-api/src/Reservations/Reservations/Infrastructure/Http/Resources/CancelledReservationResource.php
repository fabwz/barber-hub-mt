<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Reservations\Reservations\Application\DTOs\CancelledReservation;

/**
 * @property-read CancelledReservation $resource
 */
#[SchemaName('ReservaCancelada')]
final class CancelledReservationResource extends JsonResource
{
    /** The name of each class in the API, in Spanish. */
    private const array NAMES = [
        'on_time' => 'a_tiempo',
        'late' => 'tardia',
        'by_barbershop' => 'por_la_barberia',
    ];

    /** Wrap a cancelled reservation. */
    public function __construct(private readonly CancelledReservation $cancelled)
    {
        parent::__construct($cancelled);
    }

    /**
     * Expose the reservation as the creation does, plus how its cancellation is classified.
     *
     * @return array{id: string, servicio: string, barbero: string, estado: string, inicio: string, fin: string, precio: int, duracion_minutos: int, plazo_cancelacion_horas: int, expira_en: string|null, clasificacion: string}
     */
    public function toArray(Request $request): array
    {
        return [
            ...new ReservationResource($this->cancelled->reservation)->toArray($request),
            /** Clasificación de la cancelación: `a_tiempo`, `tardia` o `por_la_barberia` (CA-01, CA-04). Se calcula, no se guarda. */
            'clasificacion' => self::NAMES[$this->cancelled->class->value],
        ];
    }
}
