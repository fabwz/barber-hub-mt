<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\UseCases;

use Src\Catalog\Barbershops\Application\Contracts\BarbershopScope;
use Src\Catalog\Barbershops\Application\Contracts\OwnerAccounts;
use Src\Catalog\Barbershops\Application\Contracts\OwnerMemberships;
use Src\Catalog\Barbershops\Application\Contracts\RentInstallments;
use Src\Catalog\Barbershops\Application\DTOs\RegisterBarbershopCommand;
use Src\Catalog\Barbershops\Application\DTOs\RegisteredBarbershop;
use Src\Catalog\Barbershops\Domain\Contracts\BarbershopRepository;
use Src\Catalog\Barbershops\Domain\Entities\Barbershop;
use Src\Catalog\Barbershops\Domain\Exceptions\PublicLinkTakenException;
use Src\Catalog\Barbershops\Domain\Exceptions\PublicLinkUnavailableException;
use Src\Catalog\Barbershops\Domain\Services\PublicLinkGenerator;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Shared\Domain\Contracts\AuditLog;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Domain\ValueObjects\AuditAction;
use Src\Shared\Domain\ValueObjects\AuditEntry;
use Src\Shared\Domain\ValueObjects\Phone;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class RegisterBarbershop
{
    /** Links tried for one name before giving up; each homonym takes the next number. */
    public const int MAX_LINK_ATTEMPTS = 20;

    /** Receive the ports of the barbershop, of the account and role of its owner, of its rent and of the technical record, and the link generator. */
    public function __construct(
        private BarbershopRepository $barbershops,
        private OwnerAccounts $accounts,
        private OwnerMemberships $owners,
        private TransactionRunner $transaction,
        private PublicLinkGenerator $links,
        private RentInstallments $rent,
        private BarbershopScope $scope,
        private AuditLog $audit,
        private Clock $clock,
    ) {}

    /**
     * Register a barbershop for the platform operator who asks (RF-01, PL-02): the barbershop, with no services, no hours and the payment
     * mode «no payment», its public link, the account of its owner (the one the email already has, or a new one with a temporary password
     * that is returned this once), the owner role, the first rent installment (RF-57) and the entry of the technical record (SE-04) are
     * written in one transaction, so a failure of any of them leaves nothing of the others.
     */
    public function execute(RegisterBarbershopCommand $command): RegisteredBarbershop
    {
        $name = new BarbershopName($command->name);
        $city = new City($command->city);
        $phone = new Phone($command->phone);

        return $this->transaction->run(function () use ($command, $name, $city, $phone): RegisteredBarbershop {
            $now = $this->clock->now();
            $barbershop = $this->saveWithFreeLink($name, $city, $phone);
            $account = $this->accounts->provide($command->ownerName, $command->ownerEmail, $command->ownerPhone);
            $this->owners->assignOwner($barbershop->id, $account->userId);
            $this->rent->openFor($barbershop->id, $now);

            $entry = AuditEntry::of(
                new UserId($command->operatorId),
                AuditAction::BarbershopRegistered,
                'barbershop',
                $barbershop->id->value,
                [],
                ['public_link' => $barbershop->link->value, 'owner_user_id' => $account->userId, 'owner_account_created' => $account->created()],
                $now,
            );

            if ($entry !== null) {
                $this->scope->run($barbershop->id->value, fn () => $this->audit->record($entry));
            }

            return new RegisteredBarbershop($barbershop, $account->email, $account->created(), $account->temporaryPassword);
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
