<?php

declare(strict_types=1);

namespace Src\Catalog\Closures\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Src\Catalog\Closures\Application\UseCases\DeleteClosure;
use Src\Catalog\Closures\Application\UseCases\ListClosures;
use Src\Catalog\Closures\Application\UseCases\RegisterClosure;
use Src\Catalog\Closures\Infrastructure\Http\Requests\StoreClosureRequest;
use Src\Catalog\Closures\Infrastructure\Http\Resources\ClosureResource;
use Src\Shared\Domain\Contracts\Clock;

#[Group('Cierres de la barbería', 'Fechas en las que la barbería no atiende aunque su horario semanal diga lo contrario (feriados y cierres especiales).')]
final readonly class ClosureController
{
    /** Receive the port that tells the current instant. */
    public function __construct(private Clock $clock) {}

    /** List the closures of the barbershop from today onward. */
    #[Endpoint(
        title: 'Listar los cierres desde hoy',
        description: 'Trae los cierres con fecha de hoy en adelante (hora de Costa Rica), ordenados por fecha. Solo el dueño: un colaborador recibe 403 `prohibido` y quien no tiene rol en la barbería, 404 `no-encontrado`.',
    )]
    public function index(ListClosures $listClosures): AnonymousResourceCollection
    {
        return ClosureResource::collection($listClosures->execute($this->clock->now()));
    }

    /** Register a closure of the barbershop for a date. */
    #[Endpoint(
        title: 'Registrar un cierre por fecha',
        description: 'Cierra la barbería en la `fecha` (AAAA-MM-DD, hoy o posterior): ese día no tiene espacios aunque el horario semanal los tenga. Una fecha pasada responde 422 `datos-invalidos` y una fecha que ya tiene cierre, 409 `conflicto`. Las reservas ya confirmadas ese día no se modifican (HO-04); su lista para el dueño llega con las reservas. Solo el dueño.',
    )]
    public function store(StoreClosureRequest $request, RegisterClosure $registerClosure): JsonResponse
    {
        return new ClosureResource($registerClosure->execute($request->toCommand(), $this->clock->now()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** Delete a closure so the barbershop opens again that day. */
    #[Endpoint(
        title: 'Eliminar un cierre',
        description: 'La fecha vuelve a regirse por el horario semanal. Responde 404 `no-encontrado` si el cierre no existe en la barbería. Solo el dueño.',
    )]
    public function destroy(Request $request, DeleteClosure $deleteClosure): Response
    {
        // Route parameters reach the method by position and {barberia} comes first, so the closure is read by name.
        $deleteClosure->execute((string) $request->route('cierre'));

        return response()->noContent();
    }
}
