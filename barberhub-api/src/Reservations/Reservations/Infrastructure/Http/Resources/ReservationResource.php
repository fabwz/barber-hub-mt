<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Resources;

use DateTimeImmutable;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;

/**
 * @property-read Reservation $resource
 */
#[SchemaName('Reserva')]
final class ReservationResource extends JsonResource
{
    /** Wrap a reservation. */
    public function __construct(private readonly Reservation $reservation)
    {
        parent::__construct($reservation);
    }

    /**
     * Expose the reservation with the price, duration and cancellation deadline it kept when it was made (SV-03).
     *
     * @return array{id: string, servicio: string, barbero: string, estado: string, inicio: string, fin: string, precio: int, duracion_minutos: int, plazo_cancelacion_horas: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->reservation->id->value,
            'servicio' => $this->reservation->serviceId->value,
            'barbero' => $this->reservation->collaboratorId->value,
            /** Estado de la reserva; al crearla sin pago queda `confirmada`. */
            'estado' => $this->statusName($this->reservation->status),
            /** Inicio del servicio, fecha y hora ISO 8601 con el desfase de Costa Rica (-06:00). */
            'inicio' => $this->moment($this->reservation->interval->start),
            /** Fin del servicio, sin la limpieza que le sigue; mismo formato que `inicio`. */
            'fin' => $this->moment($this->reservation->interval->end),
            /** Colones con el IVA incluido, el precio vigente al reservar. */
            'precio' => $this->reservation->price->colones,
            'duracion_minutos' => $this->reservation->duration->minutes,
            /** Horas antes del inicio hasta las cuales se cancela sin penalización, el plazo vigente al reservar. */
            'plazo_cancelacion_horas' => $this->reservation->cancellationDeadlineHours,
        ];
    }

    /** The name of the status in the API. */
    private function statusName(ReservationStatus $status): string
    {
        return match ($status) {
            ReservationStatus::Temporary => 'temporal',
            ReservationStatus::Confirmed => 'confirmada',
            ReservationStatus::Completed => 'completada',
            ReservationStatus::NoShow => 'ausente',
            ReservationStatus::CancelledByCustomer => 'cancelada_por_cliente',
            ReservationStatus::CancelledByBarbershop => 'cancelada_por_barberia',
            ReservationStatus::Expired => 'expirada',
        };
    }

    /** Write an instant as ISO 8601 in the time zone of the application, Costa Rica. */
    private function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone((string) config('app.timezone')))->format(DATE_ATOM);
    }
}
