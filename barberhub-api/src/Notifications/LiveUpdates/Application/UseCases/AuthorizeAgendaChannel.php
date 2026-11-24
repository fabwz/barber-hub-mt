<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Application\UseCases;

use Src\Notifications\LiveUpdates\Application\Exceptions\ChannelForbiddenException;
use Src\Notifications\LiveUpdates\Application\Exceptions\ChannelNotFoundException;
use Src\Shared\Domain\Contracts\BarbershopRoles;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class AuthorizeAgendaChannel
{
    /** Receive the port that tells the role of a person in a barbershop. */
    public function __construct(private BarbershopRoles $roles) {}

    /**
     * Let the owner listen to the agenda channel of their barbershop (RF-36). A barbershop that does not exist, an identifier that is not
     * one and a person with no role there are the same not found as any resource of another barbershop (UP-06, MT-03); a collaborator or a
     * customer of the barbershop are refused (UP-02): the collaborator listens to their own channel and the customer to none.
     *
     * @throws ChannelNotFoundException
     * @throws ChannelForbiddenException
     */
    public function execute(string $userId, string $barbershopId): void
    {
        try {
            $role = $this->roles->roleOf(new UserId($userId), new BarbershopId(strtolower($barbershopId)));
        } catch (InvalidValueException) {
            throw ChannelNotFoundException::create();
        }

        if ($role === null) {
            throw ChannelNotFoundException::create();
        }

        if ($role !== 'owner') {
            throw ChannelForbiddenException::create();
        }
    }
}
