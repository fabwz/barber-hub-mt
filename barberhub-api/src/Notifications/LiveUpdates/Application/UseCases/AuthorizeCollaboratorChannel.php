<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Application\UseCases;

use Src\Notifications\LiveUpdates\Application\Contracts\AgendaCollaborators;
use Src\Notifications\LiveUpdates\Application\Exceptions\ChannelForbiddenException;
use Src\Notifications\LiveUpdates\Application\Exceptions\ChannelNotFoundException;
use Src\Shared\Domain\Contracts\BarbershopRoles;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class AuthorizeCollaboratorChannel
{
    /** Receive the port that tells the role of a person in a barbershop and the one that finds the profile of a collaborator. */
    public function __construct(private BarbershopRoles $roles, private AgendaCollaborators $collaborators) {}

    /**
     * Let a collaborator listen to the channel of their own agenda and nobody else (CO-04, RF-36). The owner hears the whole agenda in the
     * channel of the barbershop, so they are refused here, as is a customer (UP-02). A person with no role, a barbershop that does not
     * exist, an identifier that is not valid and the agenda of another collaborator are the same not found as a missing resource (UP-06).
     *
     * @throws ChannelNotFoundException
     * @throws ChannelForbiddenException
     */
    public function execute(string $userId, string $barbershopId, string $collaboratorId): void
    {
        try {
            $barbershop = new BarbershopId(strtolower($barbershopId));
            $role = $this->roles->roleOf(new UserId($userId), $barbershop);
        } catch (InvalidValueException) {
            throw ChannelNotFoundException::create();
        }

        if ($role === null) {
            throw ChannelNotFoundException::create();
        }

        if ($role !== 'collaborator') {
            throw ChannelForbiddenException::create();
        }

        $profile = $this->collaborators->profileOf($userId, $barbershop->value);

        if ($profile === null || $profile !== strtolower($collaboratorId)) {
            throw ChannelNotFoundException::create();
        }
    }
}
