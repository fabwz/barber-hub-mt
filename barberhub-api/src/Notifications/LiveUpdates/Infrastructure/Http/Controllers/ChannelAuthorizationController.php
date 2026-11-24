<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\JsonResponse;
use Src\Notifications\LiveUpdates\Infrastructure\Broadcasting\AgendaChannelAuthorizer;
use Src\Notifications\LiveUpdates\Infrastructure\Http\Requests\AuthorizeChannelRequest;

final readonly class ChannelAuthorizationController
{
    /**
     * Sign the subscription of a person to a private channel when they may listen to it.
     *
     * @response array{auth: string}
     */
    #[Endpoint(
        title: 'Autorizar la suscripción a un canal de tiempo real',
        description: 'El cliente de WebSockets llama a esta ruta, con el token de la sesión, antes de suscribirse a un canal privado (cuerpo `socket_id` y `channel_name`, con el prefijo `private-`). Los canales son `private-barberia.{barberia}.agenda`, con todos los cambios de la agenda de la barbería, solo para su dueño, y `private-barberia.{barberia}.colaboradores.{colaborador}`, con los de la agenda de un colaborador, solo para ese colaborador (CO-04). Responde 200 con la firma de la suscripción. Una persona sin rol en la barbería, una barbería que no existe, un identificador que no es válido y la agenda de otro colaborador responden el mismo 404 `no-encontrado`; un cliente, un colaborador en el canal de la barbería o el dueño en el de un colaborador, 403 `prohibido`; un nombre de canal que no existe, 403 `prohibido`. La autorización se evalúa solo al suscribirse: una conexión abierta sigue recibiendo hasta que se reconecte, y la autorización se vuelve a pedir en cada reconexión. Los eventos no llevan datos personales: el cliente pide el detalle a la API.',
    )]
    public function __invoke(AuthorizeChannelRequest $request, BroadcastManager $broadcasts, AgendaChannelAuthorizer $channels): JsonResponse
    {
        $connection = $broadcasts->connection();
        $channels->register($connection);

        return new JsonResponse($connection->auth($request));
    }
}
