<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Catalog\Barbershops\Application\DTOs\MyBarbershop;
use Src\Catalog\Barbershops\Infrastructure\Http\RoleNames;

/**
 * @property-read MyBarbershop $resource
 */
#[SchemaName('MiBarberia')]
final class MyBarbershopResource extends JsonResource
{
    /** Wrap a barbershop of the person. */
    public function __construct(private readonly MyBarbershop $barbershop)
    {
        parent::__construct($barbershop);
    }

    /**
     * Expose the barbershop and the role the person holds in it.
     *
     * @return array{id: string, nombre: string, enlace: string, rol: string}
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * Identificador de la barbería, el que las demás rutas usan en `{barberia}`.
             *
             * @example 0192f0c4-7a10-7c3e-9d21-5b8e4f6a1c02
             */
            'id' => $this->barbershop->id,
            /**
             * Nombre de la barbería.
             *
             * @example Barbería Pura Navaja
             */
            'nombre' => $this->barbershop->name,
            /**
             * Enlace público de la barbería.
             *
             * @example pura-navaja
             */
            'enlace' => $this->barbershop->link,
            /**
             * Rol que la persona tiene en esa barbería.
             *
             * @var 'dueno'|'colaborador'|'cliente'
             *
             * @example dueno
             */
            'rol' => RoleNames::of($this->barbershop->role),
        ];
    }
}
