<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Infrastructure\Broadcasting;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Src\Notifications\LiveUpdates\Application\UseCases\AuthorizeAgendaChannel;
use Src\Notifications\LiveUpdates\Application\UseCases\AuthorizeCollaboratorChannel;
use Src\Notifications\LiveUpdates\Application\UseCases\AuthorizeUserChannel;

/**
 * The private channels of the live updates and who may listen to each (RF-36, RF-35): the agenda of a barbershop, the agenda of a collaborator
 * and the channel of one person, with the offers of the waitlist. The rule lives in the use cases; the callbacks only hand them
 * the person and the identifiers of the name. A use case that refuses throws its exception, which the API renders as 404 or 403, instead of
 * answering false, which the framework would always turn into a 403. The channels are registered on the connection that is about to
 * authorize a subscription, so the server never needs them to boot.
 */
final readonly class AgendaChannelAuthorizer
{
    /** Receive the use cases that decide who listens to the agenda of a barbershop and to that of a collaborator. */
    public function __construct(private AuthorizeAgendaChannel $agenda, private AuthorizeCollaboratorChannel $collaborator, private AuthorizeUserChannel $user) {}

    /** Register the channels of the agenda on the connection. */
    public function register(Broadcaster $broadcaster): void
    {
        $broadcaster->channel('barberia.{barberia}.agenda', function (Authenticatable $user, string $barberia): bool {
            $this->agenda->execute((string) $user->getAuthIdentifier(), $barberia);

            return true;
        });

        $broadcaster->channel('barberia.{barberia}.colaboradores.{colaborador}', function (Authenticatable $user, string $barberia, string $colaborador): bool {
            $this->collaborator->execute((string) $user->getAuthIdentifier(), $barberia, $colaborador);

            return true;
        });

        $broadcaster->channel('usuario.{usuario}', function (Authenticatable $user, string $usuario): bool {
            $this->user->execute((string) $user->getAuthIdentifier(), $usuario);

            return true;
        });
    }
}
