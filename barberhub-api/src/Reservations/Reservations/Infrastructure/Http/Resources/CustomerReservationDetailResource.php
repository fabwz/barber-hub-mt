<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Reservations\Reservations\Application\DTOs\ReservationDetail;

/**
 * @property-read ReservationDetail $resource
 */
#[SchemaName('ReservaDetalleDeCliente')]
final class CustomerReservationDetailResource extends JsonResource
{
    /** Wrap the detail of a reservation. */
    public function __construct(private readonly ReservationDetail $detail)
    {
        parent::__construct($detail);
    }

    /**
     * Expose the reservation of the listing plus the deadline it kept, and nothing of the balance or the history: the
     * customer does not see the authors (RF-14) and the balance is shown to the staff (PA-10).
     *
     * @return array{id: string, servicio: string, servicio_nombre: string, barbero: string, barbero_nombre: string, estado: string, inicio: string, fin: string, precio: int, duracion_minutos: int, plazo_cancelacion_horas: int}
     */
    public function toArray(Request $request): array
    {
        return [
            ...new ReservationListItemResource($this->detail->view)->toArray($request),
            /** Horas antes del inicio hasta las cuales se cancela sin penalización, el plazo vigente al reservar. */
            'plazo_cancelacion_horas' => $this->detail->view->reservation->cancellationDeadlineHours,
        ];
    }
}
