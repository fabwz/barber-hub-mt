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
     * set are left out, and `redes` is left out when none is set. Nothing of the owner's account is exposed.
     *
     * @return array{id: string, nombre: string, ciudad: string, telefono: string, direccion?: string, correo_contacto?: string, redes?: array{whatsapp?: string, instagram?: string, facebook?: string, tiktok?: string}}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->barbershop->id,
            'nombre' => $this->barbershop->name,
            'ciudad' => $this->barbershop->city,
            'telefono' => $this->barbershop->phone,
            ...($this->barbershop->address === null ? [] : ['direccion' => $this->barbershop->address]),
            ...($this->barbershop->contactEmail === null ? [] : ['correo_contacto' => $this->barbershop->contactEmail]),
            ...($this->barbershop->socialLinks === [] ? [] : ['redes' => $this->barbershop->socialLinks]),
        ];
    }
}
