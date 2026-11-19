<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Src\Shared\Domain\Contracts\Clock;
use Src\Waitlist\Entries\Application\UseCases\JoinWaitlist;
use Src\Waitlist\Entries\Application\UseCases\LeaveWaitlist;
use Src\Waitlist\Entries\Application\UseCases\ListMyWaitlistEntries;
use Src\Waitlist\Entries\Infrastructure\Http\Requests\JoinWaitlistRequest;
use Src\Waitlist\Entries\Infrastructure\Http\Resources\WaitlistEntryResource;

#[Group('Lista de espera', 'Anotarse en la fila de espera de un servicio, un día y un barbero, ver las filas propias y salir de ellas.')]
final readonly class WaitlistEntryController
{
    /** Receive the port that tells the current instant, which is the place of a new row in the line. */
    public function __construct(private Clock $clock) {}

    /** Join the waitlist. */
    #[Endpoint(
        title: 'Anotarse en la lista de espera',
        description: 'Anota a la persona de la sesión en la fila de un `servicio` activo en una `fecha` (`AAAA-MM-DD`, hoy o posterior en Costa Rica), con el `barbero` indicado o con cualquiera si se omite o va nulo. La fila nace `esperando` y su `ingreso` (la hora de la petición) la ordena: el primero en entrar es el primero en recibir una oferta. Solo se anota quien no tiene dónde reservar: si hay espacios disponibles para esa combinación, la petición se rechaza con 409 `conflicto` y el miembro `espacios` trae hasta 3, los de inicio más próximo, con `barbero`, `inicio` y `fin` (ISO 8601), para que reserve uno con `POST /reservas`; no se crea ninguna fila. Cada persona tiene como máximo 3 filas activas (`esperando` u `ofrecida`) por barbería: la cuarta responde 409 `conflicto`, también cuando dos anotaciones llegan a la vez (el bloqueo por persona las atiende una tras otra). Repetir la misma combinación de servicio, día y barbero con una fila activa responde 409 `conflicto`. Con la lista de espera desactivada por la barbería (`lista_espera_activa` en `false`) responde 409 `conflicto`. Una persona sin rol en la barbería queda como cliente con su primera fila; si la anotación falla no queda ninguna membresía. El dueño o un colaborador de esa barbería recibe 403 `prohibido`. Un servicio o un barbero inexistente, inactivo o de otra barbería responde 404 `no-encontrado`, igual que una barbería inexistente; un dato mal escrito o una fecha pasada, 422 `datos-invalidos` con el campo. La oferta del espacio liberado todavía no se aplica.',
    )]
    public function store(JoinWaitlistRequest $request, JoinWaitlist $joinWaitlist): JsonResponse
    {
        return new WaitlistEntryResource($joinWaitlist->execute($request->toCommand(), $this->clock->now()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** List the active rows of the person. */
    #[Endpoint(
        title: 'Listar mis filas de espera',
        description: 'Lista, de la que entró primero a la última, las filas activas (`esperando` u `ofrecida`) que la persona de la sesión tiene en la barbería: a lo sumo 3, así que no va paginada. No muestra filas de otras personas. Una persona que todavía no se anotó ahí recibe la lista vacía, no un error. El dueño o un colaborador de esa barbería recibe 403 `prohibido`.',
    )]
    public function mine(Request $request, ListMyWaitlistEntries $listEntries): AnonymousResourceCollection
    {
        return WaitlistEntryResource::collection($listEntries->execute((string) $request->user()?->getAuthIdentifier()));
    }

    /** Leave the waitlist. */
    #[Endpoint(
        title: 'Salir de la lista de espera',
        description: 'Saca a la persona de una de sus filas en cualquier momento, también con la lista de espera desactivada: la fila queda `retirada` (no se borra) y deja de contar para el máximo de 3. Repetir la petición responde 204 igual y no cambia nada. Una fila ya `cumplida` con una reserva responde 409 `conflicto`. Una fila que no existe, de otra persona o de otra barbería, y una persona sin rol en la barbería, responden el mismo 404 `no-encontrado`: una persona nunca ve ni modifica las filas de otras. El dueño o un colaborador de esa barbería recibe 403 `prohibido`.',
    )]
    public function destroy(Request $request, string $barberia, string $fila, LeaveWaitlist $leaveWaitlist): Response
    {
        $leaveWaitlist->execute((string) $request->user()?->getAuthIdentifier(), $fila, $this->clock->now());

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
