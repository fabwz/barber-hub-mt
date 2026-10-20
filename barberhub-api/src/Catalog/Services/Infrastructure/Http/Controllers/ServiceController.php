<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Src\Catalog\Services\Application\UseCases\CreateService;
use Src\Catalog\Services\Application\UseCases\ListServices;
use Src\Catalog\Services\Application\UseCases\UpdateService;
use Src\Catalog\Services\Infrastructure\Http\Requests\StoreServiceRequest;
use Src\Catalog\Services\Infrastructure\Http\Requests\UpdateServiceRequest;
use Src\Catalog\Services\Infrastructure\Http\Resources\ServiceResource;
use Symfony\Component\HttpFoundation\Response;

#[Group('Servicios de la barbería', 'Administración de los servicios por el dueño de la barbería.')]
final readonly class ServiceController
{
    /** List every service of the barbershop. */
    #[Endpoint(
        title: 'Listar los servicios de la barbería',
        description: 'Trae todos los servicios, activos e inactivos, ordenados por nombre. Cada uno trae su `precio` con el IVA de 13 % incluido, desglosado en `base` e `iva`. Solo el dueño: un colaborador recibe 403 `prohibido` y quien no tiene rol en la barbería, 404 `no-encontrado`.',
    )]
    public function index(ListServices $listServices): AnonymousResourceCollection
    {
        return ServiceResource::collection($listServices->execute());
    }

    /** Register a service of the barbershop. */
    #[Endpoint(
        title: 'Crear un servicio',
        description: 'Crea un servicio activo con nombre, duración (10 a 180 minutos, de 5 en 5) y precio en colones enteros mayores que cero, con el IVA incluido. Una duración o un precio fuera de rango responde 422 `datos-invalidos` y `errores` indica el campo. Solo el dueño.',
    )]
    public function store(StoreServiceRequest $request, CreateService $createService): JsonResponse
    {
        $service = $createService->execute($request->toCommand());

        return new ServiceResource($service)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED)
            ->header('Location', $request->url().'/'.$service->id->value);
    }

    /** Change, deactivate or reactivate a service of the barbershop. */
    #[Endpoint(
        title: 'Editar, inactivar o reactivar un servicio',
        description: 'Cambia solo los campos que vienen. `activo: false` inactiva el servicio, que deja de ofrecerse pero se conserva para el historial, y `activo: true` lo reactiva; un servicio nunca se borra. Las reservas ya hechas conservan el precio y la duración con que se hicieron (SV-03). Responde 404 `no-encontrado` si el servicio no existe en la barbería. Solo el dueño.',
    )]
    public function update(UpdateServiceRequest $request, UpdateService $updateService): ServiceResource
    {
        return new ServiceResource($updateService->execute($request->toCommand()));
    }
}
