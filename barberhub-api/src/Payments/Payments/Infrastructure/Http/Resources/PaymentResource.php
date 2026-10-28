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
     * @return array{id: string, reserva: string, estado: string, metodo: string, monto: int, motivo: string|null, devolucion_pendiente: bool, accion_siguiente: array<string, mixed>|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->payment->id->value,
            'reserva' => $this->payment->reservationId->value,
            /** Estado del pago: `pendiente`, `aprobado`, `rechazado`, `fallido` o `reembolsado`. La reserva se confirma solo cuando el pago queda `aprobado`. */
            'estado' => PaymentNames::status($this->payment->status),
            /** Medio de pago: `tarjeta` o `sinpe_movil`. */
            'metodo' => PaymentNames::method($this->payment->method),
            /** Colones cobrados: el depósito o el total, según la política de la barbería. */
            'monto' => $this->payment->amount->colones,
            /** Motivo del rechazo o del fallo; nulo en los demás estados. */
            'motivo' => $this->payment->failureReason,
            /** Verdadero si el pago se aprobó cuando la reserva ya no se podía confirmar y hay que devolverlo (PA-07). */
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
