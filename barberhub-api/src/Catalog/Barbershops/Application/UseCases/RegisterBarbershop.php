<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\UseCases;

use Src\Catalog\Barbershops\Application\Contracts\OwnerMemberships;
use Src\Catalog\Barbershops\Application\DTOs\RegisterBarbershopCommand;
use Src\Catalog\Barbershops\Domain\Contracts\BarbershopRepository;
use Src\Catalog\Barbershops\Domain\Entities\Barbershop;
use Src\Catalog\Barbershops\Domain\Exceptions\PublicLinkTakenException;
use Src\Catalog\Barbershops\Domain\Exceptions\PublicLinkUnavailableException;
use Src\Catalog\Barbershops\Domain\Services\PublicLinkGenerator;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Domain\ValueObjects\Phone;

final readonly class RegisterBarbershop
{
    /** Links tried for one name before giving up; each homonym takes the next number. */
    public const int MAX_LINK_ATTEMPTS = 20;

    /** Receive the barbershop repository, owner memberships and transaction ports, and the link generator. */
    public function __construct(
        private BarbershopRepository $barbershops,
        private OwnerMemberships $owners,
        private TransactionRunner $transaction,
        private PublicLinkGenerator $links,
    ) {}

    /** Register a barbershop and its owner together, so neither exists without the other (RF-01, RF-02, UP-05). */
    public function execute(RegisterBarbershopCommand $command): Barbershop
    {
        $name = new BarbershopName($command->name);
        $city = new City($command->city);
        $phone = new Phone($command->phone);

        return $this->transaction->run(function () use ($command, $name, $city, $phone): Barbershop {
            $barbershop = $this->saveWithFreeLink($name, $city, $phone);
            $this->owners->assignOwner($barbershop->id, $command->ownerId);

            return $barbershop;
        });
    }

    /**
     * Save the barbershop under the first link the database accepts. The unique key decides, not a prior check,
     * so two simultaneous registrations of the same name cannot end up with the same link (MT-07).
     */
    private function saveWithFreeLink(BarbershopName $name, City $city, Phone $phone): Barbershop
    {
        $id = $this->barbershops->nextId();

        for ($number = 1; $number <= self::MAX_LINK_ATTEMPTS; $number++) {
            $barbershop = Barbershop::create($id, $name, $city, $phone, $this->links->candidate($name, $number));

            try {
                $this->barbershops->save($barbershop);

                return $barbershop;
            } catch (PublicLinkTakenException) {
                continue;
            }
        }

        throw PublicLinkUnavailableException::create();
    }
}
