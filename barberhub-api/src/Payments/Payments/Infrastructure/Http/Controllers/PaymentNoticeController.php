<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Src\Payments\Payments\Application\Exceptions\PaymentRequestRejectedException;
use Src\Payments\Payments\Application\Exceptions\PaymentServiceUnavailableException;
use Src\Payments\Payments\Application\UseCases\ReceiveGatewayNotice;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Infrastructure\Http\Middleware\AssignRequestId;

#[Group('Pagos', 'Cobro del depósito o del total de una reserva, consulta del estado del pago y reembolso.')]
final readonly class PaymentNoticeController
{
    /** Receive the port that tells the current instant. */
    public function __construct(private Clock $clock) {}

    /** Receive a notice of ONVO Pay. */
    #[Endpoint(
        title: 'Aviso de ONVO Pay',
        description: 'Dirección pública, sin sesión, a la que ONVO Pay avisa del resultado de un pago. Se registra en el panel de ONVO Pay (Developers, Webhooks) con los eventos `payment_intent.succeeded`, `payment_intent.failed` y `payment_intent.canceled`, y su secreto de firma va en `ONVO_WEBHOOK_SECRET`. El origen se verifica con el encabezado `X-Onvo-Signature` (HMAC-SHA256 de `t.cuerpo` y ventana de cinco minutos); una firma ausente, mal formada o inválida responde 401 `no-autenticado` sin cambiar ningún estado y sin decir el motivo. Sin el secreto configurado responde 503 `servicio-no-disponible` y nunca acepta. El aviso es solo un disparador: el sistema consulta a ONVO Pay el estado real del pago y, si quedó aprobado, confirma la reserva en la misma transacción; si falló, la reserva sigue temporal. Cada evento se procesa una sola vez: uno repetido, uno de un pago desconocido y uno de un tipo que el sistema no usa responden 200 con `{"recibido": true}`. Si ONVO Pay no responde al consultarlo, responde 503 `servicio-no-disponible`, el evento no queda procesado y ONVO Pay lo reintenta. El cuerpo debe ser JSON de hasta 64 KB; otro tipo de contenido o un cuerpo mayor responde 400 `solicitud-invalida`. Hay un límite de solicitudes por minuto: 429 `demasiadas-solicitudes` con `Retry-After`.',
    )]
    public function onvo(Request $request, ReceiveGatewayNotice $receive): JsonResponse
    {
        return $this->receive(PaymentProvider::Onvo, $request, $receive);
    }

    /** Receive a notice of PayPal. */
    #[Endpoint(
        title: 'Aviso de PayPal',
        description: 'Dirección pública, sin sesión, a la que PayPal avisa del resultado de un pago. Se registra en el panel de desarrolladores de PayPal (Webhooks) con los eventos `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DECLINED` y `PAYMENT.CAPTURE.DENIED`, y el identificador de ese webhook va en `PAYPAL_WEBHOOK_ID`. El origen se verifica con el servicio `verify-webhook-signature` de PayPal, que recibe los encabezados `PAYPAL-*` de la transmisión, el identificador del webhook y el evento tal como llegó; solo `SUCCESS` lo acepta. Una transmisión sin encabezados, un cuerpo que no es un evento o una verificación que no es `SUCCESS` responde 401 `no-autenticado` sin cambiar ningún estado y sin decir el motivo. Sin `PAYPAL_WEBHOOK_ID` configurado, o si PayPal no responde a la verificación, responde 503 `servicio-no-disponible` y PayPal reintenta. El aviso es solo un disparador: el sistema consulta a PayPal el estado real de la orden (y la captura si el cliente ya la aprobó) y, si el pago quedó aprobado, confirma la reserva en la misma transacción; si falló, la reserva sigue temporal. Cada evento se procesa una sola vez: uno repetido, uno de un pago desconocido y uno de un tipo que el sistema no usa responden 200 con `{"recibido": true}`. Si PayPal no responde al consultar la orden, responde 503 `servicio-no-disponible` y el evento no queda procesado. El cuerpo debe ser JSON de hasta 64 KB; otro tipo de contenido o un cuerpo mayor responde 400 `solicitud-invalida`. Hay un límite de solicitudes por minuto: 429 `demasiadas-solicitudes` con `Retry-After`.',
    )]
    public function paypal(Request $request, ReceiveGatewayNotice $receive): JsonResponse
    {
        return $this->receive(PaymentProvider::Paypal, $request, $receive);
    }

    /** Verify, apply and acknowledge a notice of a provider. */
    private function receive(PaymentProvider $provider, Request $request, ReceiveGatewayNotice $receive): JsonResponse
    {
        try {
            $result = $receive->execute($provider, $this->headersOf($request), $request->getContent(), $this->clock->now());
        } catch (PaymentServiceUnavailableException|PaymentRequestRejectedException $exception) {
            // Nothing was recorded: the provider sends the notice again.
            Log::warning('A payment notice could not be processed now.', ['gateway' => $provider->value, 'result' => 'retry', 'request_id' => Context::get(AssignRequestId::CONTEXT_KEY)]);

            throw $exception;
        }

        Log::info('A payment notice was processed.', ['gateway' => $provider->value, 'event_id' => $result->eventId, 'result' => $result->outcome, 'request_id' => Context::get(AssignRequestId::CONTEXT_KEY)]);

        return response()->json(['recibido' => true]);
    }

    /**
     * The headers of the request, one value each, by lowercase name.
     *
     * @return array<string, string>
     */
    private function headersOf(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        return $headers;
    }
}
