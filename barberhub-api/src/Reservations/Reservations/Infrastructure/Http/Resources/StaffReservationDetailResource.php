<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Resources;

use DateTimeImmutable;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Reservations\Reservations\Application\DTOs\ReservationDetail;
use Src\Reservations\Reservations\Application\DTOs\ReservationHistoryEntry;
use Src\Reservations\Reservations\Infrastructure\Http\ReservationStatusNames;

/**
 * @property-read ReservationDetail $resource
 */
#[SchemaName('ReservaDetalleDeAgenda')]
final class StaffReservationDetailResource extends JsonResource
{
    /** Wrap the detail of a reservation. */
    public function __construct(private readonly ReservationDetail $detail)
    {
        parent::__construct($detail);
    }

    /**
     * Expose the reservation of the agenda plus the deadline it kept and the balance to pay. The history belongs to the
     * owner: the answer to a collaborator has no `historial` at all (CO-04, RE-09). No payments, no income.
     *
     * @return array{id: string, servicio: string, servicio_nombre: string, barbero: string, barbero_nombre: string, estado: string, inicio: string, fin: string, precio: int, duracion_minutos: int, cliente_nombre: string, plazo_cancelacion_horas: int, saldo_pendiente: int|null, historial?: list<array{de: string|null, a: string, autor: string, fecha: string}>}
     */
    public function toArray(Request $request): array
    {
        $detail = [
            ...new StaffReservationListItemResource($this->detail->view)->toArray($request),
            /** Horas antes del inicio hasta las cuales se cancela sin penalización, el plazo vigente al reservar. */
            'plazo_cancelacion_horas' => $this->detail->view->reservation->cancellationDeadlineHours,
            /** Colones por cobrar en el local (PA-10): mientras no exista un depósito, el precio completo. */
            'saldo_pendiente' => $this->detail->pendingBalance,
        ];

        if ($this->detail->history !== null) {
            /** Cambios de estado con `de`, `a`, `autor` (nombre, o `sistema`) y `fecha`, del primero al último; solo el dueño los ve. */
            $detail['historial'] = array_map(fn (ReservationHistoryEntry $entry): array => [
                'de' => $entry->from === null ? null : ReservationStatusNames::of($entry->from),
                'a' => ReservationStatusNames::of($entry->to),
                'autor' => $entry->authorName ?? (string) __('reservations.author_system'),
                /** Fecha y hora ISO 8601 con el desfase de Costa Rica (-06:00). */
                'fecha' => $this->moment($entry->at),
            ], $this->detail->history);
        }

        return $detail;
    }

    /** Write an instant as ISO 8601 in the time zone of the application, Costa Rica. */
    private function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone((string) config('app.timezone')))->format(DATE_ATOM);
    }
}
