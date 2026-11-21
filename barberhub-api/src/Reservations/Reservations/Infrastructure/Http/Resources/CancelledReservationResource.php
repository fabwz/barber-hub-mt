<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Reservations\Reservations\Application\DTOs\CancelledReservation;
use Src\Reservations\Reservations\Infrastructure\Http\ConsequenceNames;

/**
 * @property-read CancelledReservation $resource
 */
#[SchemaName('ReservaCancelada')]
final class CancelledReservationResource extends JsonResource
{
    /** Wrap a cancelled reservation. */
    public function __construct(private readonly CancelledReservation $cancelled)
    {
        parent::__construct($cancelled);
    }

    /**
     * Expose the reservation as the creation does, plus how its cancellation is classified and what was applied to its deposit and its session.
     *
     * @return array{id: string, servicio: string, barbero: string, estado: string, inicio: string, fin: string, precio: int, duracion_minutos: int, plazo_cancelacion_horas: int, expira_en: string|null, clasificacion: string, deposito: string, sesion_bono: string}
     */
    public function toArray(Request $request): array
    {
        return [
            ...new ReservationResource($this->cancelled->reservation)->toArray($request),
            /** Clasificación de la cancelación: `a_tiempo`, `tardia` o `por_la_barberia` (CA-01, CA-04). Se calcula, no se guarda. */
            'clasificacion' => ConsequenceNames::cancellation($this->cancelled->class),
            /** Qué pasó con el depósito pagado en línea (RF-17): `a_devolver` (cancelación a tiempo o de la barbería; se reembolsa con RF-24), `retenido` (cancelación tardía) o `ninguno` (no había nada pagado en línea). Repetir la cancelación informa lo mismo. */
            'deposito' => ConsequenceNames::deposit($this->cancelled->consequence->deposit),
            /** Qué pasó con la sesión del bono que pagaba la reserva (BO-05): `se_reintegra`, `se_consume` o `ninguna` si no se pagó con un bono. */
            'sesion_bono' => ConsequenceNames::session($this->cancelled->consequence->session),
        ];
    }
}
