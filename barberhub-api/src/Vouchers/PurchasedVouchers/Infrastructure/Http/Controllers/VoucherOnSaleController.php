<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Src\Shared\Infrastructure\Http\Requests\ListRequest;
use Src\Shared\Infrastructure\Http\Resources\PageMeta;
use Src\Vouchers\PurchasedVouchers\Application\UseCases\ListVouchersOnSale;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Http\Resources\VoucherOnSaleResource;

#[Group('Bonos a la venta', 'Los bonos que vende una barbería y los servicios que cubre cada uno, para que el cliente elija cuál comprar.')]
final readonly class VoucherOnSaleController
{
    /** List the vouchers on sale in the barbershop. */
    #[Endpoint(
        title: 'Listar los bonos a la venta',
        description: 'Lista, por nombre, los bonos que la barbería tiene a la venta, cada uno con su `id`, `nombre`, `sesiones`, `vigencia_dias`, `precio` en colones con el IVA de 13 % incluido y los `servicios` que cubre (`id` y `nombre`). El `id` es el `{bono}` de `POST /barberias/{barberia}/bonos/{bono}/compras`. Un bono retirado de la venta no aparece. Lo puede leer cualquier persona con sesión, tenga o no rol en la barbería: no crea ninguna membresía (el rol de cliente nace con la primera compra). `meta.bonos_activos` dice si la barbería tiene los bonos activados; con los bonos desactivados la lista viene vacía y comprar responde 409 `conflicto`. `meta.pasarela` es la pasarela con la que la barbería cobra hoy (`onvo` o `paypal`; nula si la barbería no cobra) y `meta.metodos_de_pago` los métodos que esa pasarela admite (`tarjeta` y `sinpe_movil` con ONVO, solo `tarjeta` con PayPal; lista vacía si `pasarela` es nula), con el mismo cálculo que las reservas; se informan igual con los bonos desactivados. El dueño o un colaborador de esa barbería recibe 403 `prohibido`: sus bonos se administran con `GET /administracion/bonos`. Sin sesión, 401; una barbería inexistente, 404 `no-encontrado`. Va paginada con `pagina` y `por_pagina` (hasta 50, 15 por defecto) y `meta` trae el total.',
    )]
    public function index(ListRequest $request, ListVouchersOnSale $listVouchers): AnonymousResourceCollection
    {
        $listing = $listVouchers->execute($request->page());

        $meta = PageMeta::of($listing->page)['meta'];

        return VoucherOnSaleResource::collection($listing->page->items)->additional(['meta' => [
            ...$meta,
            'bonos_activos' => $listing->enabled,
            'pasarela' => $listing->channel?->gateway,
            'metodos_de_pago' => $listing->channel === null ? [] : $listing->channel->methods,
        ]]);
    }
}
