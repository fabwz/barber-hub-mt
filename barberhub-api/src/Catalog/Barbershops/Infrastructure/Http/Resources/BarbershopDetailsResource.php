<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Catalog\Barbershops\Domain\Entities\Barbershop;
use Src\Catalog\Barbershops\Domain\ValueObjects\SocialNetwork;

/**
 * @property-read Barbershop $resource
 */
#[SchemaName('DatosDeBarberia')]
final class BarbershopDetailsResource extends JsonResource
{
    /** Wrap the data of a barbershop that its owner edits. */
    public function __construct(private readonly Barbershop $barbershop)
    {
        parent::__construct($barbershop);
    }

    /**
     * Expose the data the owner edits. What is not set comes as null, so the owner sees every field; the networks come in an
     * object with the four keys.
     *
     * @return array{id: string, nombre: string, ciudad: string, telefono: string, enlace: string, direccion: string|null, correo_contacto: string|null, redes: array{whatsapp: string|null, instagram: string|null, facebook: string|null, tiktok: string|null}}
     */
    public function toArray(Request $request): array
    {
        $profile = $this->barbershop->profile;

        return [
            'id' => $this->barbershop->id->value,
            'nombre' => $this->barbershop->name->value,
            'ciudad' => $this->barbershop->city->value,
            'telefono' => $this->barbershop->phone->value,
            'enlace' => $this->barbershop->link->value,
            'direccion' => $profile->address?->value,
            'correo_contacto' => $profile->contactEmail?->value,
            'redes' => [
                'whatsapp' => ($profile->links[SocialNetwork::Whatsapp->value] ?? null)?->value,
                'instagram' => ($profile->links[SocialNetwork::Instagram->value] ?? null)?->value,
                'facebook' => ($profile->links[SocialNetwork::Facebook->value] ?? null)?->value,
                'tiktok' => ($profile->links[SocialNetwork::Tiktok->value] ?? null)?->value,
            ],
        ];
    }
}
