<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Catalog\Barbershops\Application\DTOs\RegisteredBarbershop;

/**
 * @property-read RegisteredBarbershop $resource
 */
#[SchemaName('Barberia')]
final class BarbershopResource extends JsonResource
{
    /** Wrap a registered barbershop with the outcome of the account of its owner. */
    public function __construct(private readonly RegisteredBarbershop $registered)
    {
        parent::__construct($registered);
    }

    /**
     * Expose the barbershop and its owner. `contrasena_temporal` is present only when the account was created by this registration
     * and is never shown again: the operator hands it over. For an account that already existed it is null and nothing about it changed.
     *
     * @return array{id: string, nombre: string, ciudad: string, telefono: string, enlace: string, dueno: array{correo: string, cuenta_creada: bool, contrasena_temporal: string|null}}
     */
    public function toArray(Request $request): array
    {
        $barbershop = $this->registered->barbershop;

        return [
            'id' => $barbershop->id->value,
            'nombre' => $barbershop->name->value,
            'ciudad' => $barbershop->city->value,
            'telefono' => $barbershop->phone->value,
            'enlace' => $barbershop->link->value,
            'dueno' => [
                'correo' => $this->registered->ownerEmail,
                'cuenta_creada' => $this->registered->ownerAccountCreated,
                'contrasena_temporal' => $this->registered->temporaryPassword,
            ],
        ];
    }
}
