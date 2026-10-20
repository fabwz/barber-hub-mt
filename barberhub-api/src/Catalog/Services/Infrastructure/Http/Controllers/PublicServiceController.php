<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Src\Catalog\Services\Application\UseCases\ListPublicServices;
use Src\Catalog\Services\Infrastructure\Http\Resources\PublicServiceResource;

#[Group('Servicios públicos', 'Consulta de los servicios de una barbería, sin iniciar sesión.')]
final readonly class PublicServiceController
{
    /** List the active services of a barbershop to any visitor. */
    #[Endpoint(
        title: 'Consultar los servicios de una barbería',
        description: 'Trae solo los servicios activos, ordenados por nombre. El `precio` incluye el IVA de 13 % y viene desglosado en `base` e `iva`, de modo que `base` más `iva` es el `precio`. Una barbería sin servicios activos, o que no existe, devuelve una lista vacía.',
    )]
    public function index(ListPublicServices $listPublicServices): AnonymousResourceCollection
    {
        return PublicServiceResource::collection($listPublicServices->execute());
    }
}
