<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Catalog\Services\Application\DTOs\PublicService;

/**
 * @property-read PublicService $resource
 */
#[SchemaName('ServicioPublico')]
final class PublicServiceResource extends JsonResource
{
    /** Wrap the public view of a service. */
    public function __construct(private readonly PublicService $service)
    {
        parent::__construct($service);
    }

    /**
     * Expose only what a customer sees: the price with VAT included, split into base and tax (RL-02).
     *
     * @return array{id: string, nombre: string, duracion_minutos: int, precio: int, base: int, iva: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->service->id,
            'nombre' => $this->service->name,
            'duracion_minutos' => $this->service->durationMinutes,
            /** Colones con el IVA incluido; es igual a `base` más `iva`. */
            'precio' => $this->service->price,
            'base' => $this->service->base,
            'iva' => $this->service->tax,
        ];
    }
}
