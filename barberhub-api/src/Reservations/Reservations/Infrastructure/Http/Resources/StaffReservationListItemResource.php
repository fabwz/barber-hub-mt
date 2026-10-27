<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Reservations\Reservations\Application\DTOs\ReservationView;

/**
 * @property-read ReservationView $resource
 */
#[SchemaName('ReservaDeAgenda')]
final class StaffReservationListItemResource extends JsonResource
{
    /** Wrap a reservation with its names, the customer's included. */
    public function __construct(private readonly ReservationView $view)
    {
        parent::__construct($view);
    }

    /**
     * Expose the reservation of a listing plus the name of the customer, and nothing else about them: never the email or
     * the phone (CO-04).
     *
     * @return array{id: string, servicio: string, servicio_nombre: string, barbero: string, barbero_nombre: string, estado: string, inicio: string, fin: string, precio: int, duracion_minutos: int, expira_en: string|null, cliente_nombre: string}
     */
    public function toArray(Request $request): array
    {
        return [
            ...new ReservationListItemResource($this->view)->toArray($request),
            /** Nombre de la persona que reservó. */
            'cliente_nombre' => (string) $this->view->customerName,
        ];
    }
}
