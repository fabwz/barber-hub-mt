<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\ServiceId;

/**
 * @property-read VoucherDefinition $resource
 */
#[SchemaName('Bono')]
final class VoucherDefinitionResource extends JsonResource
{
    /** Wrap a voucher definition. */
    public function __construct(private readonly VoucherDefinition $definition)
    {
        parent::__construct($definition);
    }

    /**
     * Expose the voucher with the services it covers.
     *
     * @return array{id: string, nombre: string, sesiones: int, precio: int, vigencia_dias: int, servicios: list<string>, activo: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->definition->id->value,
            'nombre' => $this->definition->name->value,
            'sesiones' => $this->definition->sessionsCount->value,
            /** Colones con el IVA incluido. */
            'precio' => $this->definition->price->colones,
            'vigencia_dias' => $this->definition->validityDays->value,
            /** Identificadores de los servicios que cubre. */
            'servicios' => array_map(fn (ServiceId $service): string => $service->value, $this->definition->services->ids),
            /** `false` si está retirado de la venta. */
            'activo' => $this->definition->isActive,
        ];
    }
}
