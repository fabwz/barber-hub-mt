<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\VoucherForSale;

/**
 * @property-read VoucherForSale $resource
 */
#[SchemaName('BonoALaVenta')]
final class VoucherOnSaleResource extends JsonResource
{
    /** Wrap a voucher on sale with the services it covers. */
    public function __construct(private readonly VoucherForSale $voucher)
    {
        parent::__construct($voucher);
    }

    /**
     * Expose what a customer needs to choose a voucher: its terms, its price and the services it covers. Nothing of the owner's side
     * (the state of the sale) is given: every voucher listed is on sale.
     *
     * @return array{id: string, nombre: string, sesiones: int, vigencia_dias: int, precio: int, servicios: AnonymousResourceCollection}
     */
    public function toArray(Request $request): array
    {
        $terms = $this->voucher->terms;

        return [
            /** Identificador del bono; es el `{bono}` de `POST /barberias/{barberia}/bonos/{bono}/compras`. */
            'id' => $terms->id->value,
            'nombre' => $terms->name->value,
            /** Sesiones que trae el bono al comprarlo. Ejemplo: 5. */
            'sesiones' => $terms->sessionsCount->value,
            /** Días de vigencia, que cuentan desde que se confirma el pago. Ejemplo: 90. */
            'vigencia_dias' => $terms->validityDays->value,
            /** Precio en colones con el IVA de 13 % incluido. Ejemplo: 30000. */
            'precio' => $terms->price->colones,
            /** Servicios que cubre, ordenados por nombre. Cada uno trae su `id` y su `nombre` actual (por ejemplo, Corte clásico). */
            'servicios' => CoveredServiceResource::collection($this->voucher->services),
        ];
    }
}
