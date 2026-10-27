<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Src\Catalog\Barbershops\Application\UseCases\GetPaymentPolicy;
use Src\Catalog\Barbershops\Application\UseCases\UpdatePaymentPolicy;
use Src\Catalog\Barbershops\Infrastructure\Http\Requests\UpdatePaymentPolicyRequest;
use Src\Catalog\Barbershops\Infrastructure\Http\Resources\PaymentPolicyResource;

#[Group('Cobro de la barbería', 'Modalidad de cobro, depósito, pasarela y cancelación gratuita, a cargo del dueño.')]
final readonly class PaymentPolicyController
{
    /** Show the payment policy of the barbershop. */
    #[Endpoint(
        title: 'Consultar la política de cobro',
        description: 'Trae la `modalidad` (`sin_pago`, `deposito` o `total`), el `porcentaje_deposito` (solo con `deposito`; `null` en las otras), el `plazo_cancelacion_horas` de cancelación gratuita y la `pasarela` (`onvo`, `paypal` o `null`). Una barbería nueva no cobra, sin pasarela y con 24 horas de plazo. Solo el dueño: un colaborador recibe 403 `prohibido` y quien no tiene rol en la barbería, 404 `no-encontrado`.',
    )]
    public function show(GetPaymentPolicy $getPaymentPolicy): PaymentPolicyResource
    {
        return new PaymentPolicyResource($getPaymentPolicy->execute());
    }

    /** Change the payment policy of the barbershop. */
    #[Endpoint(
        title: 'Cambiar la política de cobro',
        description: 'Cambia solo los campos que vienen. El depósito va de 10 a 100 % y es obligatorio con `deposito`; con `sin_pago` o `total` no se admite. El plazo va de 0 a 720 horas. `deposito` y `total` exigen una `pasarela`. Un valor fuera de rango o una combinación inválida responde 422 `datos-invalidos`. El cambio aplica a las reservas y los cobros nuevos: las reservas ya creadas conservan el plazo con que se hicieron (CA-01). Solo el dueño.',
    )]
    public function update(UpdatePaymentPolicyRequest $request, UpdatePaymentPolicy $updatePaymentPolicy): PaymentPolicyResource
    {
        return new PaymentPolicyResource($updatePaymentPolicy->execute($request->toCommand()));
    }
}
