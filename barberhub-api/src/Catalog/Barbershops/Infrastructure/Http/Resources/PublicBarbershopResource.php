<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Catalog\Barbershops\Application\DTOs\PublicBarbershop;

/**
 * @property-read PublicBarbershop $resource
 */
#[SchemaName('BarberiaPublica')]
final class PublicBarbershopResource extends JsonResource
{
    /** Wrap the public view of a barbershop. */
    public function __construct(private readonly PublicBarbershop $barbershop)
    {
        parent::__construct($barbershop);
    }

    /**
     * Expose only what any visitor may know about the barbershop. The address, the contact email and the networks that are not
     * set are left out, and `redes` is left out when none is set. Nothing of the owner's account is exposed. The logo and the banner are left out when the barbershop has none, so the client shows a monogram and a compact header (RF-46).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->barbershop->id,
            'nombre' => $this->barbershop->name,
            'ciudad' => $this->barbershop->city,
            'telefono' => $this->barbershop->phone,
            'direccion' => $this->when($this->barbershop->address !== null, $this->barbershop->address),
            'correo_contacto' => $this->when($this->barbershop->contactEmail !== null, $this->barbershop->contactEmail),
            /** Dirección del logo, relativa al servidor (`/api/v1/archivos/...`). Falta si la barbería no tiene logo (el cliente muestra un monograma). */
            'logo' => $this->when($this->barbershop->logo !== null, $this->barbershop->logo),
            /** Dirección del banner, relativa al servidor. Falta si la barbería no tiene banner (el cliente muestra una cabecera compacta). */
            'banner' => $this->when($this->barbershop->banner !== null, $this->barbershop->banner),
            'redes' => $this->when($this->barbershop->socialLinks !== [], $this->barbershop->socialLinks),
            /** Si la barbería tiene lista de espera; con `false` el cliente no ve la opción de anotarse (RF-09, LE-01). */
            'lista_espera_activa' => $this->barbershop->waitlistEnabled,
        ];
    }
}
