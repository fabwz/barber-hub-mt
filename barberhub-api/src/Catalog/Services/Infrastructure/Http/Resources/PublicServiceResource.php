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
     * Expose only what a customer sees: the price with VAT included, split into base and tax (RL-02), and the image when the service has one: a service without it is shown without it (SV-07).
     *
     * @return array<string, mixed>
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
            /** Dirección de la imagen del servicio, relativa al servidor (`/api/v1/archivos/...`). Falta si el servicio no tiene imagen (SV-07). */
            'imagen' => $this->when($this->service->image !== null, $this->service->image),
        ];
    }
}
