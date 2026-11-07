<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Resources;

use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Reservations\Reservations\Application\DTOs\PaymentDue;
use Src\Reservations\Reservations\Application\DTOs\ReservationView;

/**
 * @property-read ReservationView $resource
 */
#[SchemaName('ReservaDeCliente')]
final class CustomerReservationListItemResource extends JsonResource
{
    /** Wrap a reservation of the customer with its names and what is owed now. */
    public function __construct(private readonly ReservationView $view)
    {
        parent::__construct($view);
    }

    /**
     * Expose the reservation as the customer sees it in a listing: the item of the listing plus what they must pay now (RF-20).
     *
     * @return array{id: string, servicio: string, servicio_nombre: string, barbero: string, barbero_nombre: string, estado: string, inicio: string, fin: string, precio: int, duracion_minutos: int, expira_en: string|null, monto_a_pagar: int, saldo_en_local: int, vence_en: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            ...new ReservationListItemResource($this->view)->toArray($request),
            ...self::payment($this->view->payment ?? new PaymentDue(0, $this->view->reservation->price->colones, null)),
        ];
    }

    /**
     * The three attributes of what is owed now, shared by every reading of the customer.
     *
     * @return array{monto_a_pagar: int, saldo_en_local: int, vence_en: string|null}
     */
    public static function payment(PaymentDue $due): array
    {
        return [
            /** Colones que el servidor cobraría hoy por esta reserva según la política de la barbería (`sin_pago` 0, `deposito` el porcentaje del precio redondeado hacia arriba, `total` el precio); 0 si la reserva ya no espera pago o ya está pagada. Ejemplo: 2550. */
            'monto_a_pagar' => $due->amount,
            /** Colones del precio que no se pagan ahora, es decir, lo que se cobra en el local: el precio menos `monto_a_pagar`. Ejemplo: 5950. */
            'saldo_en_local' => $due->balanceAtShop,
            /** Hora límite para pagar la reserva temporal, ISO 8601 con el desfase de Costa Rica (-06:00); nula si no es temporal o si no hay nada que pagar. Ejemplo: 2030-03-01T08:10:00-06:00. */
            'vence_en' => $due->expiresAt === null ? null : $due->expiresAt->setTimezone(new DateTimeZone((string) config('app.timezone')))->format(DATE_ATOM),
        ];
    }
}
