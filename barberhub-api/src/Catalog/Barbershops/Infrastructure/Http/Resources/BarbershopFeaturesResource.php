<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopFeatures;

/**
 * @property-read BarbershopFeatures $resource
 */
#[SchemaName('Funciones')]
final class BarbershopFeaturesResource extends JsonResource
{
    /** Wrap the switches of a barbershop. */
    public function __construct(private readonly BarbershopFeatures $features)
    {
        parent::__construct($features);
    }

    /**
     * Expose the switches: whether it sells vouchers and whether it has a waitlist.
     *
     * @return array{bonos_activos: bool, lista_espera_activa: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'bonos_activos' => $this->features->vouchersEnabled,
            'lista_espera_activa' => $this->features->waitlistEnabled,
        ];
    }
}
