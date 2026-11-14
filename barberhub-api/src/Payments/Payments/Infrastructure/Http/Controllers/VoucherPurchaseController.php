<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Src\Payments\Payments\Application\Exceptions\PaymentRequestRejectedException;
use Src\Payments\Payments\Application\Exceptions\PaymentServiceUnavailableException;
use Src\Payments\Payments\Application\UseCases\PayVoucher;
use Src\Payments\Payments\Infrastructure\Http\Requests\PayVoucherRequest;
use Src\Payments\Payments\Infrastructure\Http\Resources\PaymentResource;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Infrastructure\Http\Problem\ProblemDetailsRenderer;

#[Group('Compra de bonos', 'Compra de un bono de la barbería, cobro con la pasarela de la barbería y activación con el aviso verificado de su pago.')]
final readonly class VoucherPurchaseController
{
    /** Receive the port that tells the current instant. */
    public function __construct(private Clock $clock) {}

    /** Buy a voucher. */
    #[Endpoint(
        title: 'Comprar un bono',
        description: 'El cliente compra un bono que está a la venta en la barbería con `metodo` `tarjeta` o `sinpe_movil`. El cuerpo es el mismo de `POST /reservas/{reserva}/pagos`: además de `metodo`, lleva solo los campos que la pasarela configurada para la barbería exige para ese medio, y el número de la tarjeta nunca llega a este servidor. El monto es siempre el precio completo del bono con el IVA de 13 % incluido, tal como estaba a la venta al comprar; nunca viene del cuerpo (un `monto` que llegue se ignora) y la modalidad de cobro de las reservas (depósito) no se aplica. En una sola transacción se registra el bono comprado como pendiente de pago, sin sesiones y sin vencimiento, y se crea el pago ligado a él; si la persona no tenía rol en la barbería, queda como cliente en esa misma transacción y, si algo falla, no queda ninguna membresía. Responde 201 con el pago, con el mismo formato que `POST /reservas/{reserva}/pagos` (`accion_siguiente` dice qué hace el cliente), y `bono_comprado` trae el id del bono comprado. El bono NO se activa con esta respuesta ni con lo que informe el navegador: se activa solo cuando el aviso verificado de la pasarela (o la lectura de su estado con `GET /pagos/{pago}`) confirma el pago; entonces el pago queda `aprobado`, el bono queda activo con todas sus sesiones y su vigencia en días cuenta desde ese momento, en la misma transacción. Un pago `rechazado` o `fallido` deja el bono sin activar y sin sesiones; la persona puede volver a intentarlo y se reutiliza el mismo bono pendiente. Si la persona ya tiene un pago `pendiente` o `aprobado` de ese bono, lo devuelve con 200 y no cobra otra vez. El bono no es reembolsable ni transferible. Si la pasarela no responde responde 503 `servicio-no-disponible` y si no acepta los datos, 422 `datos-invalidos`, igual que el pago de una reserva. Un bono inexistente, retirado de la venta o de otra barbería responde 404 `no-encontrado`, igual que una barbería inexistente; el dueño o un colaborador de esa barbería recibe 403 `prohibido` y no se crea nada; sin sesión, 401.',
    )]
    #[ApiResponse(201, 'El pago de la compra, pendiente hasta que el aviso verificado de la pasarela lo confirme. `accion_siguiente` dice qué hace el cliente; `bono_comprado` es el bono pendiente de activación.', type: 'PaymentResource')]
    #[ApiResponse(200, 'La persona ya tenía un pago pendiente o aprobado de ese bono: se devuelve el mismo y no se cobra otra vez.', type: 'PaymentResource')]
    public function store(PayVoucherRequest $request, string $barberia, string $bono, PayVoucher $payVoucher): JsonResponse
    {
        $outcome = $payVoucher->execute($request->toCommand($bono), $this->clock->now());

        // The payment is already saved: the 503 and the 422 are responses, not exceptions, so the transaction of the request commits.
        if ($outcome->gatewayUnavailable || $outcome->requestRejected) {
            $renderer = new ProblemDetailsRenderer;

            return $renderer($outcome->requestRejected ? PaymentRequestRejectedException::create() : PaymentServiceUnavailableException::create());
        }

        return new PaymentResource($outcome->payment, $outcome->nextAction)
            ->response()
            ->setStatusCode($outcome->created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
