<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Resources;

use DateTimeImmutable;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Reservations\Reservations\Application\DTOs\ReservationView;
use Src\Reservations\Reservations\Infrastructure\Http\ReservationStatusNames;

/**
 * @property-read ReservationView $resource
 */
#[SchemaName('ReservaDeLista')]
final class ReservationListItemResource extends JsonResource
{
    /** Wrap a reservation with its names. */
    public function __construct(private readonly ReservationView $view)
    {
        parent::__construct($view);
    }

    /**
     * Expose the reservation as it appears in a listing: with the names of the service and the barber, and the price and
     * duration it kept when it was made (SV-03). It never carries the customer: that is for the agenda of the staff.
     *
     * @return array{id: string, servicio: string, servicio_nombre: string, barbero: string, barbero_nombre: string, estado: string, inicio: string, fin: string, precio: int, duracion_minutos: int}
     */
    public function toArray(Request $request): array
    {
        $reservation = $this->view->reservation;

        return [
            'id' => $reservation->id->value,
            'servicio' => $reservation->serviceId->value,
            /** Nombre actual del servicio. */
            'servicio_nombre' => $this->view->serviceName,
            'barbero' => $reservation->collaboratorId->value,
            /** Nombre del barbero. */
            'barbero_nombre' => $this->view->collaboratorName,
            /** Estado de la reserva. */
            'estado' => ReservationStatusNames::of($reservation->status),
            /** Inicio del servicio, fecha y hora ISO 8601 con el desfase de Costa Rica (-06:00). */
            'inicio' => $this->moment($reservation->interval->start),
            /** Fin del servicio, sin la limpieza que le sigue; mismo formato que `inicio`. */
            'fin' => $this->moment($reservation->interval->end),
            /** Colones con el IVA incluido, el precio vigente al reservar. */
            'precio' => $reservation->price->colones,
            'duracion_minutos' => $reservation->duration->minutes,
        ];
    }

    /** Write an instant as ISO 8601 in the time zone of the application, Costa Rica. */
    private function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone((string) config('app.timezone')))->format(DATE_ATOM);
    }
}
