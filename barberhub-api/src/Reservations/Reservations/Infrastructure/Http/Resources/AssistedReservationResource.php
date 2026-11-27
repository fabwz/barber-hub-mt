<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Reservations\Reservations\Domain\Entities\Reservation;

/**
 * @property-read Reservation $resource
 */
#[SchemaName('ReservaAsistida')]
final class AssistedReservationResource extends JsonResource
{
    /** Wrap the assisted reservation just registered. */
    public function __construct(private readonly Reservation $reservation)
    {
        parent::__construct($reservation);
    }

    /**
     * Expose the reservation as it was made, which is always `confirmada`, plus the assisted client it is for.
     *
     * @return array{id: string, servicio: string, barbero: string, estado: string, inicio: string, fin: string, precio: int, duracion_minutos: int, plazo_cancelacion_horas: int, expira_en: string|null, cliente_asistido: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            ...new ReservationResource($this->reservation)->toArray($request),
            /** Identificador del cliente asistido de la reserva, el encontrado o el recién registrado. */
            'cliente_asistido' => $this->reservation->assistedClientId?->value,
        ];
    }
}
