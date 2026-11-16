<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Catalog\Barbershops\Application\DTOs\DirectoryBarbershop;

/**
 * @property-read DirectoryBarbershop $resource
 */
#[SchemaName('BarberiaDelDirectorio')]
final class DirectoryBarbershopResource extends JsonResource
{
    /** Wrap the entry of a barbershop in the directory. */
    public function __construct(private readonly DirectoryBarbershop $barbershop)
    {
        parent::__construct($barbershop);
    }

    /**
     * Expose only the four public fields. There is no identifier, phone, email or data of the owner: the `enlace` is the door to
     * the barbershop, and `GET /enlaces/{enlace}` gives the rest (RD-18, MT-09).
     *
     * @return array{nombre: string, ciudad: string, logo: string|null, enlace: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'nombre' => $this->barbershop->name,
            'ciudad' => $this->barbershop->city,
            /** Dirección del logo, o `null` si la barbería no tiene. */
            'logo' => $this->barbershop->logo,
            /** Enlace público; sirve en `GET /enlaces/{enlace}`. */
            'enlace' => $this->barbershop->link,
        ];
    }
}
