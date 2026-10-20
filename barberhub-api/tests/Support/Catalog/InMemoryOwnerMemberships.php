<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use Src\Catalog\Barbershops\Application\Contracts\OwnerMemberships;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Throwable;

final class InMemoryOwnerMemberships implements OwnerMemberships
{
    /** @var list<array{barbershopId: string, userId: string}> */
    private array $assigned = [];

    private ?Throwable $failure = null;

    /** Make every later assignment fail with the given error. */
    public function failWith(Throwable $failure): void
    {
        $this->failure = $failure;
    }

    /** Record the owner unless a failure was set. */
    public function assignOwner(BarbershopId $barbershopId, string $userId): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->assigned[] = ['barbershopId' => $barbershopId->value, 'userId' => $userId];
    }

    /**
     * List the owners assigned so far.
     *
     * @return list<array{barbershopId: string, userId: string}>
     */
    public function assigned(): array
    {
        return $this->assigned;
    }
}
