<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Infrastructure\Http\Requests\ListRequest;
use Src\Shared\Infrastructure\Http\Resources\PageMeta;
use Src\Vouchers\PurchasedVouchers\Application\UseCases\ListCustomerPurchasedVouchers;
use Src\Vouchers\PurchasedVouchers\Application\UseCases\ListSoldPurchasedVouchers;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Http\Resources\PurchasedVoucherResource;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Http\Resources\SoldVoucherResource;

#[Group('Saldo de bonos', 'Consulta del saldo y la vigencia de los bonos comprados: el cliente ve los suyos y el dueño, los vendidos en su barbería.')]
final readonly class PurchasedVoucherController
{
    /** Receive the port that tells the current instant, at which the validity is read. */
    public function __construct(private Clock $clock) {}

    /** List the vouchers the person bought in the barbershop. */
    #[Endpoint(
        title: 'Listar mis bonos',
        description: 'Lista, del activado más recientemente al más antiguo, los bonos pagados que la persona de la sesión compró en la barbería, con su `saldo` en sesiones (igual a la suma de sus `movimientos`: compra, apartado, consumo y reintegro), su `estado`, `activado_en` y `vence_en`, y en cada movimiento la `reserva` a la que se liga. Un bono vencido muestra `saldo` 0, `estado` `vencido` y su fecha de vencimiento; se calcula al consultar, sin proceso de vencimiento. Un bono que todavía espera su pago no aparece. Una persona que todavía no compró ahí recibe la lista vacía, no un error. El dueño o un colaborador de esa barbería recibe 403 `prohibido`: la vista del dueño es `GET /administracion/bonos-vendidos`. Va paginada con `pagina` y `por_pagina` (hasta 50, 15 por defecto) y `meta` trae el total.',
    )]
    public function mine(ListRequest $request, ListCustomerPurchasedVouchers $listVouchers): AnonymousResourceCollection
    {
        $page = $listVouchers->execute((string) $request->user()?->getAuthIdentifier(), $request->page(), $this->clock->now());

        return PurchasedVoucherResource::collection($page->items)->additional(PageMeta::of($page));
    }

    /** List the vouchers sold in the barbershop. */
    #[Endpoint(
        title: 'Listar los bonos vendidos',
        description: 'Lista, del activado más recientemente al más antiguo, los bonos pagados que los clientes compraron en la barbería, con el nombre del cliente (`cliente_nombre`; nunca su correo ni su teléfono) y lo mismo que ve el cliente: `saldo`, `estado`, `activado_en`, `vence_en` y los `movimientos` con su `reserva`. Un bono vencido muestra `saldo` 0, `estado` `vencido` y su fecha de vencimiento. Un bono que todavía espera su pago no aparece. Solo el dueño: un colaborador o un cliente reciben 403 `prohibido` y quien no tiene rol en la barbería, 404 `no-encontrado`. Va paginada con `pagina` y `por_pagina` (hasta 50, 15 por defecto) y `meta` trae el total.',
    )]
    public function sold(ListRequest $request, ListSoldPurchasedVouchers $listVouchers): AnonymousResourceCollection
    {
        $page = $listVouchers->execute($request->page(), $this->clock->now());

        return SoldVoucherResource::collection($page->items)->additional(PageMeta::of($page));
    }
}
