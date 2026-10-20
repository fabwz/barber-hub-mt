<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\UseCases;

use Src\Catalog\Barbershops\Application\Contracts\OwnerMemberships;
use Src\Catalog\Barbershops\Application\DTOs\RegisterBarbershopCommand;
use Src\Catalog\Barbershops\Domain\Contracts\BarbershopRepository;
use Src\Catalog\Barbershops\Domain\Entities\Barbershop;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Domain\ValueObjects\Phone;

final readonly class RegisterBarbershop
{
    /** Receive the barbershop repository, owner memberships and transaction ports. */
    public function __construct(
        private BarbershopRepository $barbershops,
        private OwnerMemberships $owners,
        private TransactionRunner $transaction,
    ) {}

    /** Register a barbershop and its owner together, so neither exists without the other (RF-01, UP-05). */
    public function execute(RegisterBarbershopCommand $command): Barbershop
    {
        $name = new BarbershopName($command->name);
        $city = new City($command->city);
        $phone = new Phone($command->phone);

        return $this->transaction->run(function () use ($command, $name, $city, $phone): Barbershop {
            $barbershop = Barbershop::create($this->barbershops->nextId(), $name, $city, $phone);

            $this->barbershops->save($barbershop);
            $this->owners->assignOwner($barbershop->id, $command->ownerId);

            return $barbershop;
        });
    }
}
