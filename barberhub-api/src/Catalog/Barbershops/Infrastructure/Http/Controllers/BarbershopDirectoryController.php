<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Src\Catalog\Barbershops\Application\UseCases\ListDirectoryBarbershops;
use Src\Catalog\Barbershops\Infrastructure\Http\Requests\ListDirectoryRequest;
use Src\Catalog\Barbershops\Infrastructure\Http\Resources\DirectoryBarbershopResource;
use Src\Shared\Infrastructure\Http\Resources\PageMeta;

#[Group('Directorio de barberías', 'Las barberías que su dueño decidió mostrar al público, sin iniciar sesión.')]
final readonly class BarbershopDirectoryController
{
    /** List the barbershops that appear in the public directory. */
    #[Endpoint(
        title: 'Listar el directorio de barberías',
        description: 'Lista, por nombre, solo las barberías cuyo dueño activó `aparece_en_directorio` (por defecto, ninguna). Cada una trae `nombre`, `ciudad`, `logo` (`null` si no tiene) y `enlace`; nunca un identificador, un teléfono, un correo ni datos del dueño. `buscar` filtra por nombre o ciudad sin distinguir mayúsculas ni tildes. Una barbería que no aparece sigue accesible por su enlace en `GET /enlaces/{enlace}`, que es la puerta de entrada de cada una. Va paginada con `pagina` y `por_pagina` (hasta 50, 15 por defecto) y `meta` trae el total. No requiere sesión.',
    )]
    public function index(ListDirectoryRequest $request, ListDirectoryBarbershops $listDirectoryBarbershops): AnonymousResourceCollection
    {
        $page = $listDirectoryBarbershops->execute($request->search(), $request->page());

        return DirectoryBarbershopResource::collection($page->items)->additional(PageMeta::of($page));
    }
}
