<?php

declare(strict_types=1);

namespace Src\Reservations\AssistedClients\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Src\Reservations\AssistedClients\Application\UseCases\SearchAssistedClients;
use Src\Reservations\AssistedClients\Infrastructure\Http\Requests\ListAssistedClientsRequest;
use Src\Reservations\AssistedClients\Infrastructure\Http\Resources\AssistedClientResource;

#[Group('Clientes asistidos', 'Personas sin cuenta que el dueño registra para una reserva asistida; existen solo en su barbería.')]
final readonly class AssistedClientController
{
    /** Search the assisted clients of the barbershop. */
    #[Endpoint(
        title: 'Buscar clientes asistidos',
        description: 'Busca entre los clientes asistidos de la barbería por una parte del `nombre` o del teléfono (`busqueda`, sin distinguir mayúsculas), ordenados por nombre y hasta 10; sin `busqueda` trae los primeros. Nunca trae a un cliente asistido de otra barbería (CL-06). No hay lista paginada: el dueño escribe más para acotar. Solo el dueño: un colaborador o un cliente recibe 403 `prohibido` y quien no tiene rol en la barbería, 404 `no-encontrado`. El cliente asistido se crea al registrar la reserva (`POST /reservas/asistida`).',
    )]
    public function index(ListAssistedClientsRequest $request, SearchAssistedClients $search): AnonymousResourceCollection
    {
        return AssistedClientResource::collection($search->execute($request->term()));
    }
}
