<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Src\Catalog\Barbershops\Application\UseCases\RegisterBarbershop;
use Src\Catalog\Barbershops\Infrastructure\Http\Requests\RegisterBarbershopRequest;
use Src\Catalog\Barbershops\Infrastructure\Http\Resources\BarbershopResource;

#[Group('Barberías', 'Alta de barberías y de su dueño.')]
final readonly class BarbershopController
{
    /** Register a barbershop and make the authenticated person its owner. */
    #[Endpoint(
        title: 'Registrar una barbería',
        description: 'Crea una barbería con nombre, ciudad y teléfono de contacto, sin servicios ni horario, y deja a la persona autenticada como su dueña activa (`rol: dueno`). Una persona puede registrar varias barberías. Responde 401 `no-autenticado` sin token y 422 `datos-invalidos` si falta un dato o supera su largo máximo (`errores` lista cada campo).',
    )]
    public function store(RegisterBarbershopRequest $request, RegisterBarbershop $registerBarbershop): JsonResponse
    {
        /** @var string $ownerId */
        $ownerId = $request->user()?->getAuthIdentifier();

        return new BarbershopResource($registerBarbershop->execute($request->toCommand($ownerId)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
