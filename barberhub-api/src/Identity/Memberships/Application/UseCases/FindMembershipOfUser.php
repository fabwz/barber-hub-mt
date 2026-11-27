<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Application\UseCases;

use Src\Identity\Memberships\Domain\Contracts\MembershipRepository;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class FindMembershipOfUser
{
    /** Receive the membership repository port. */
    public function __construct(private MembershipRepository $memberships) {}

    /** Give the identifier of the role a person holds in a barbershop, whatever it is, or none if they have no role there. */
    public function execute(string $userId, string $barbershopId): ?string
    {
        return $this->memberships->findByUserAndBarbershop(new UserId($userId), new BarbershopId($barbershopId))?->id->value;
    }
}
