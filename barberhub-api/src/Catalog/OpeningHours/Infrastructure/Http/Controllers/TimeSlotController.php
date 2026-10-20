<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Src\Catalog\OpeningHours\Application\UseCases\AddTimeSlot;
use Src\Catalog\OpeningHours\Application\UseCases\DeleteTimeSlot;
use Src\Catalog\OpeningHours\Application\UseCases\ListTimeSlots;
use Src\Catalog\OpeningHours\Infrastructure\Http\Requests\StoreTimeSlotRequest;
use Src\Catalog\OpeningHours\Infrastructure\Http\Resources\TimeSlotResource;

#[Group('Horario de la barbería', 'Franjas de atención por día de la semana; el día 1 es lunes y el 7 es domingo. Un día sin franjas está cerrado.')]
final readonly class TimeSlotController
{
    /** List the weekly schedule of the barbershop. */
    #[Endpoint(
        title: 'Consultar el horario semanal',
        description: 'Trae todas las franjas, ordenadas por día y hora de apertura. Las horas son `HH:MM` en hora de Costa Rica. Solo el dueño: un colaborador recibe 403 `prohibido` y quien no tiene rol en la barbería, 404 `no-encontrado`.',
    )]
    public function index(ListTimeSlots $listTimeSlots): AnonymousResourceCollection
    {
        return TimeSlotResource::collection($listTimeSlots->execute());
    }

    /** Add a slot to the weekly schedule of the barbershop. */
    #[Endpoint(
        title: 'Agregar una franja al horario',
        description: 'La apertura está incluida en la franja y el cierre excluido, así que 09:00–12:00 y 12:00–15:00 no se traslapan. Una franja que se traslapa con otra del mismo día responde 409 `conflicto`; un cierre que no es posterior a la apertura, 422 `datos-invalidos`. Solo el dueño.',
    )]
    public function store(StoreTimeSlotRequest $request, AddTimeSlot $addTimeSlot): JsonResponse
    {
        return new TimeSlotResource($addTimeSlot->execute($request->toCommand()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** Remove a slot from the weekly schedule of the barbershop. */
    #[Endpoint(
        title: 'Eliminar una franja del horario',
        description: 'Quitar todas las franjas de un día lo deja cerrado. Responde 404 `no-encontrado` si la franja no existe en la barbería. Solo el dueño.',
    )]
    public function destroy(Request $request, DeleteTimeSlot $deleteTimeSlot): Response
    {
        // Route parameters reach the method by position and {barberia} comes first, so the slot is read by name.
        $deleteTimeSlot->execute((string) $request->route('franja'));

        return response()->noContent();
    }
}
