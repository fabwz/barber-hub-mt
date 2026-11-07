<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Reservations\Reservations\Application\DTOs\PaymentDue;
use Src\Reservations\Reservations\Domain\Entities\Reservation;

/**
 * @property-read Reservation $resource
 */
#[SchemaName('ReservaCreada')]
final class CreatedReservationResource extends JsonResource
{
    /** Wrap the reservation just made with what is owed now. */
    public function __construct(private readonly Reservation $reservation, private readonly PaymentDue $due)
    {
        parent::__construct($reservation);
    }

    /**
     * Expose the reservation as it was made plus what the customer must pay now (RF-20).
     *
     * @return array{id: string, servicio: string, barbero: string, estado: string, inicio: string, fin: string, precio: int, duracion_minutos: int, plazo_cancelacion_horas: int, expira_en: string|null, monto_a_pagar: int, saldo_en_local: int, vence_en: string|null, pasarela: string|null, metodos_de_pago: list<string>}
     */
    public function toArray(Request $request): array
    {
        return [
            ...new ReservationResource($this->reservation)->toArray($request),
            ...CustomerReservationListItemResource::payment($this->due),
        ];
    }
}
