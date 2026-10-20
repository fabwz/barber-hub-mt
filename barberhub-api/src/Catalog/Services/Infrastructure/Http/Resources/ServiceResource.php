<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Catalog\Services\Domain\Entities\Service;

/**
 * @property-read Service $resource
 */
#[SchemaName('Servicio')]
final class ServiceResource extends JsonResource
{
    /** Wrap a service. */
    public function __construct(private readonly Service $service)
    {
        parent::__construct($service);
    }

    /**
     * Expose the service with its price split into base and tax (RL-02).
     *
     * @return array{id: string, nombre: string, duracion_minutos: int, precio: int, base: int, iva: int, activo: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->service->id->value,
            'nombre' => $this->service->name->value,
            'duracion_minutos' => $this->service->duration->minutes(),
            /** Colones con el IVA incluido; es igual a `base` más `iva`. */
            'precio' => $this->service->price->colones(),
            'base' => $this->service->price->base()->colones,
            'iva' => $this->service->price->tax()->colones,
            'activo' => $this->service->active,
        ];
    }
}
