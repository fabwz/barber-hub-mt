<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Payments\Payments\Application\DTOs\NextAction;
use Src\Payments\Payments\Application\DTOs\NextActionType;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Infrastructure\Http\PaymentNames;

/**
 * It names no provider: the API of payments is the same whichever gateway the barbershop uses.
 *
 * @property-read Payment $resource
 */
#[SchemaName('Pago')]
final class PaymentResource extends JsonResource
{
    /** Wrap a payment with what the customer does next, if the provider asked for something. */
    public function __construct(private readonly Payment $payment, private readonly ?NextAction $nextAction = null)
    {
        parent::__construct($payment);
    }

    /**
     * Expose the payment: its state, the method, the amount in colones and, only right after it is created, the next action.
     *
     * @return array{id: string, reserva: string|null, bono_comprado: string|null, estado: string, metodo: string, monto: int, motivo: string|null, devolucion_pendiente: bool, accion_siguiente: array<string, mixed>|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->payment->id->value,
            /** Reserva que paga este pago; nulo si paga un bono. */
            'reserva' => $this->payment->reservationId?->value,
            /** Bono comprado que paga este pago; nulo si paga una reserva. El bono se activa cuando el pago queda `aprobado` por el aviso verificado de la pasarela. */
            'bono_comprado' => $this->payment->purchasedVoucherId?->value,
            /** Estado del pago: `pendiente`, `aprobado`, `rechazado`, `fallido` o `reembolsado`. La reserva se confirma solo cuando el pago queda `aprobado`. */
            'estado' => PaymentNames::status($this->payment->status),
            /** Medio de pago: `tarjeta` o `sinpe_movil`. */
            'metodo' => PaymentNames::method($this->payment->method),
            /** Colones cobrados: el depósito o el total de la reserva según la política de la barbería, o el precio completo del bono. */
            'monto' => $this->payment->amount->colones,
            /**
             * Motivo del rechazo o del fallo, siempre en español y con lo que el cliente puede hacer; nulo en los demás estados. Es el mismo con cualquier pasarela y nunca trae el texto del proveedor: un motivo que el sistema no reconoce es una frase genérica.
             *
             * @example El banco rechazó el pago. Pruebe con otra tarjeta o con otro método.
             */
            'motivo' => $this->payment->failureReason,
            /** Verdadero si el pago se aprobó cuando la reserva ya no se podía confirmar (o el bono no se pudo activar) y hay que devolverlo (PA-07). */
            'devolucion_pendiente' => $this->payment->refundPending,
            /** Solo al crear el pago: `redireccion` con la `url` a la que va el cliente a pagar o a verificar su tarjeta, o `instrucciones` con el `numero_destino` y el `monto` exacto de la transferencia SINPE Móvil. */
            'accion_siguiente' => $this->nextAction === null ? null : $this->nextActionToArray($this->nextAction),
        ];
    }

    /**
     * The next action as the API shows it.
     *
     * @return array<string, mixed>
     */
    private function nextActionToArray(NextAction $action): array
    {
        return match ($action->type) {
            NextActionType::Redirect => ['tipo' => 'redireccion', 'url' => $action->value],
            NextActionType::Instructions => ['tipo' => 'instrucciones', 'numero_destino' => $action->value, 'monto' => $action->amount?->colones],
        };
    }
}
