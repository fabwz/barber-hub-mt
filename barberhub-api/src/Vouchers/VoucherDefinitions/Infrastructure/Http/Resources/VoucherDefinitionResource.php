<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Vouchers\VoucherDefinitions\Application\DTOs\VoucherDefinitionWithSales;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\ServiceId;

/**
 * @property-read VoucherDefinitionWithSales $resource
 */
#[SchemaName('Bono')]
final class VoucherDefinitionResource extends JsonResource
{
    /** Wrap a voucher definition with its sales flag. */
    public function __construct(private readonly VoucherDefinitionWithSales $listing)
    {
        parent::__construct($listing);
    }

    /**
     * Expose the voucher with the services it covers and whether it has sales.
     *
     * @return array{id: string, nombre: string, sesiones: int, precio: int, vigencia_dias: int, servicios: list<string>, activo: bool, tiene_ventas: bool}
     */
    public function toArray(Request $request): array
    {
        $definition = $this->listing->definition;

        return [
            'id' => $definition->id->value,
            'nombre' => $definition->name->value,
            'sesiones' => $definition->sessionsCount->value,
            /** Colones con el IVA incluido. */
            'precio' => $definition->price->colones,
            'vigencia_dias' => $definition->validityDays->value,
            /**
             * Identificadores de los servicios que cubre.
             *
             * @var list<string> $services
             */
            'servicios' => array_map(fn (ServiceId $service): string => $service->value, $definition->services->ids),
            /** `false` si está retirado de la venta. */
            'activo' => $definition->isActive,
            /** `true` si algún cliente ya lo compró: sus `sesiones`, su `vigencia_dias` y sus `servicios` ya no se pueden cambiar (409 `bono-con-ventas`). */
            'tiene_ventas' => $this->listing->hasSales,
        ];
    }
}
