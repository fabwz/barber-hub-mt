<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Src\Catalog\Barbershops\Application\UseCases\GetBarbershopFeatures;
use Src\Catalog\Barbershops\Application\UseCases\UpdateBarbershopFeatures;
use Src\Catalog\Barbershops\Infrastructure\Http\Requests\UpdateBarbershopFeaturesRequest;
use Src\Catalog\Barbershops\Infrastructure\Http\Resources\BarbershopFeaturesResource;

#[Group('Funciones de la barbería', 'Activar o desactivar los bonos y la lista de espera, a cargo del dueño.')]
final readonly class BarbershopFeaturesController
{
    /** Show the switches of the barbershop. */
    #[Endpoint(
        title: 'Consultar las funciones',
        description: 'Trae `bonos_activos` (venta de bonos nuevos) y `lista_espera_activa`. Una barbería nueva los tiene en `false`. Solo el dueño: un colaborador o un cliente recibe 403 `prohibido` y quien no tiene rol en la barbería, 404 `no-encontrado`.',
    )]
    public function show(GetBarbershopFeatures $getBarbershopFeatures): BarbershopFeaturesResource
    {
        return new BarbershopFeaturesResource($getBarbershopFeatures->execute());
    }

    /** Change the switches of the barbershop. */
    #[Endpoint(
        title: 'Activar o desactivar las funciones',
        description: 'Cambia solo los campos que vienen. Con `bonos_activos` en `false` no se pueden comprar ni definir bonos nuevos (409 `conflicto`), pero las definiciones se siguen editando, retirando y listando, y los bonos ya comprados conservan su saldo y su vigencia. Con `lista_espera_activa` en `false` el cliente no ve la opción de anotarse; las ofertas en curso no se cancelan. El cambio aplica a las operaciones nuevas. Solo el dueño.',
    )]
    public function update(UpdateBarbershopFeaturesRequest $request, UpdateBarbershopFeatures $updateBarbershopFeatures): BarbershopFeaturesResource
    {
        return new BarbershopFeaturesResource($updateBarbershopFeatures->execute($request->toCommand()));
    }
}
