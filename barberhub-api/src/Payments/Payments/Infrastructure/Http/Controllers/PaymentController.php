<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Src\Payments\Payments\Application\Exceptions\PaymentRequestRejectedException;
use Src\Payments\Payments\Application\Exceptions\PaymentServiceUnavailableException;
use Src\Payments\Payments\Application\UseCases\GetPayment;
use Src\Payments\Payments\Application\UseCases\PayReservation;
use Src\Payments\Payments\Application\UseCases\RefundPayment;
use Src\Payments\Payments\Infrastructure\Http\Requests\PayReservationRequest;
use Src\Payments\Payments\Infrastructure\Http\Resources\PaymentResource;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Infrastructure\Http\Problem\ProblemDetailsRenderer;
use Src\Shared\Infrastructure\Tenancy\RequestBarbershopContext;

#[Group('Pagos', 'Cobro del depósito o del total de una reserva, consulta del estado del pago y reembolso.')]
final readonly class PaymentController
{
    /** Receive the port that tells the current instant. */
    public function __construct(private Clock $clock) {}

    /** Pay a temporary reservation. */
    #[Endpoint(
        title: 'Pagar una reserva',
        description: 'El cliente paga su reserva `temporal` con `metodo` `tarjeta` o `sinpe_movil`. Además de `metodo`, el cuerpo lleva solo los campos que la pasarela configurada para la barbería exige para ese medio (`token`, o `telefono`, `identificacion` y `tipo_identificacion`); si la pasarela no necesita ninguno, basta `metodo` y el cliente paga en la página de la pasarela. Los campos que la pasarela no usa se ignoran y nunca se le envían; el número de la tarjeta nunca llega a este servidor. El monto lo calcula el servidor: el precio que la reserva guardó, completo o el porcentaje de depósito de la barbería redondeado hacia arriba al colón; nunca viene del cuerpo: un `monto` o `amount` que llegue se ignora. Responde 201 con el pago; si el estado es `pendiente`, `accion_siguiente` dice qué hace el cliente (ir a una página o transferir por SINPE Móvil el monto exacto) y el estado final se obtiene con `GET /pagos/{pago}`. Un pago `rechazado` trae el `motivo` y deja la reserva temporal para reintentar. Si la reserva ya tiene un pago `pendiente` o `aprobado`, devuelve ese pago con 200 y no cobra otra vez. Una reserva que no está temporal y vigente, o de una barbería que no cobra, responde 409 `conflicto`. `tipo_identificacion` se acepta como `"0"` o `0` y solo admite 0, 1, 2, 3, 4, 5 y 9; otro valor responde 422 `datos-invalidos` en ese campo. Si la pasarela no responde (tiempo de espera, conexión, 5xx) o rechaza nuestras credenciales responde 503 `servicio-no-disponible`: si no llegó a crear el cobro el pago queda `fallido` y se puede reintentar; si falló al confirmarlo queda `pendiente` y no se vuelve a cobrar solo. Si la pasarela no acepta los datos del pago (400 o 422 suyo) antes de crear el cobro, el pago queda `fallido` y responde 422 `datos-invalidos` sin detalles internos. Un método que la pasarela de la barbería no admite responde 422 `datos-invalidos` en `metodo`. Una reserva inexistente, de otra barbería o de otro cliente responde 404 `no-encontrado`; el dueño o un colaborador recibe 403 `prohibido`.',
    )]
    public function store(PayReservationRequest $request, string $barberia, string $reserva, PayReservation $payReservation): JsonResponse
    {
        $outcome = $payReservation->execute($request->toCommand($reserva), $this->clock->now());

        // The payment is already saved: the 503 and the 422 are responses, not exceptions, so the transaction of the request commits.
        if ($outcome->gatewayUnavailable || $outcome->requestRejected) {
            $renderer = new ProblemDetailsRenderer;

            return $renderer($outcome->requestRejected ? PaymentRequestRejectedException::create() : PaymentServiceUnavailableException::create());
        }

        return new PaymentResource($outcome->payment, $outcome->nextAction)
            ->response()
            ->setStatusCode($outcome->created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /** Tell the customer who comes back from the payment page to ask for the state of the payment. */
    #[Endpoint(
        title: 'Regreso de la página de pago',
        description: 'Dirección pública a la que la pasarela devuelve al cliente después de la página de pago. Solo responde un mensaje: no cambia ningún estado ni confía en lo que reciba por la dirección. El estado del pago siempre se consulta con `GET /barberias/{barberia}/pagos/{pago}`.',
    )]
    public function returned(): JsonResponse
    {
        return response()->json(['mensaje' => __('payments.returned')]);
    }

    /** Show a payment, asking the gateway for its state. */
    #[Endpoint(
        title: 'Consultar un pago',
        description: 'Consulta el estado del pago a la pasarela (nunca confía en el navegador), lo actualiza y, si quedó `aprobado`, confirma la reserva en la misma transacción: no queda una reserva confirmada sin su pago ni un pago aprobado sin su reserva. Un pago `rechazado`, `fallido` o ya resuelto se devuelve tal cual. Si el pago se aprobó cuando la reserva ya había vencido y no se pudo confirmar, queda `aprobado` con `devolucion_pendiente` verdadero (PA-07) y la reserva sigue expirada. La puede consultar el cliente dueño de la reserva o el dueño de la barbería; un colaborador recibe 403 `prohibido`. Un pago inexistente, de otra barbería o de otro cliente responde 404 `no-encontrado`. Si la pasarela no responde, 503 `servicio-no-disponible` y el pago queda como estaba.',
    )]
    public function show(Request $request, string $barberia, string $pago, GetPayment $getPayment): PaymentResource
    {
        $isOwner = $request->attributes->get(RequestBarbershopContext::ROLE_ATTRIBUTE) === 'owner';

        return new PaymentResource($getPayment->execute((string) $request->user()?->getAuthIdentifier(), $isOwner, $pago, $this->clock->now()));
    }

    /** Give a payment back. */
    #[Endpoint(
        title: 'Reembolsar un pago',
        description: 'El dueño devuelve el monto completo de un pago `aprobado` por la misma pasarela con que se cobró. Si la pasarela acepta, el pago queda `reembolsado`. Si la rechaza, el pago sigue `aprobado` y la respuesta es 409 `conflicto` con el `motivo`. Un pago que no está aprobado, o cobrado con una pasarela que no implementa reembolsos, responde 409 `conflicto`; si la pasarela no responde, 503 `servicio-no-disponible`. Solo el dueño: un colaborador o un cliente recibe 403 `prohibido`; un pago inexistente o de otra barbería, 404 `no-encontrado`.',
    )]
    public function refund(string $barberia, string $pago, RefundPayment $refundPayment): PaymentResource
    {
        return new PaymentResource($refundPayment->execute($pago, $this->clock->now()));
    }
}
