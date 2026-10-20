<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Catalog\Barbershops\Domain\Entities\Barbershop;

/**
 * @property-read Barbershop $resource
 */
#[SchemaName('Barberia')]
final class BarbershopResource extends JsonResource
{
    /** Wrap a registered barbershop. */
    public function __construct(private readonly Barbershop $barbershop)
    {
        parent::__construct($barbershop);
    }

    /**
     * Expose the barbershop and the role of the person who registered it, who is always its owner.
     *
     * @return array{id: string, nombre: string, ciudad: string, telefono: string, rol: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->barbershop->id->value,
            'nombre' => $this->barbershop->name->value,
            'ciudad' => $this->barbershop->city->value,
            'telefono' => $this->barbershop->phone->value,
            'rol' => 'dueno',
        ];
    }
}
