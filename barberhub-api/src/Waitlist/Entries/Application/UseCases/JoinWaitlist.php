<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\UseCases;

use DateTimeImmutable;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Waitlist\Entries\Application\Contracts\CustomerMemberships;
use Src\Waitlist\Entries\Application\Contracts\OpenSpaces;
use Src\Waitlist\Entries\Application\Contracts\WaitlistCatalog;
use Src\Waitlist\Entries\Application\Contracts\WaitlistSettings;
use Src\Waitlist\Entries\Application\DTOs\JoinWaitlistCommand;
use Src\Waitlist\Entries\Domain\Contracts\WaitlistEntryRepository;
use Src\Waitlist\Entries\Domain\Entities\WaitlistEntry;
use Src\Waitlist\Entries\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Waitlist\Entries\Domain\Exceptions\DesiredDateInPastException;
use Src\Waitlist\Entries\Domain\Exceptions\DuplicateEntryException;
use Src\Waitlist\Entries\Domain\Exceptions\ServiceNotFoundException;
use Src\Waitlist\Entries\Domain\Exceptions\SpacesAvailableException;
use Src\Waitlist\Entries\Domain\Exceptions\WaitlistDisabledException;
use Src\Waitlist\Entries\Domain\Exceptions\WaitlistLimitReachedException;
use Src\Waitlist\Entries\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Entries\Domain\ValueObjects\CustomerMembershipId;
use Src\Waitlist\Entries\Domain\ValueObjects\DesiredDate;
use Src\Waitlist\Entries\Domain\ValueObjects\ServiceId;

final readonly class JoinWaitlist
{
    /** Receive the repository, the ports to the switches, the catalog, the availability and the memberships, and the transaction port. */
    public function __construct(
        private WaitlistEntryRepository $entries,
        private WaitlistSettings $settings,
        private WaitlistCatalog $catalog,
        private OpenSpaces $spaces,
        private CustomerMemberships $customers,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Put a customer in the line for a service on a day, with one collaborator or with any (RF-30, LE-01, LE-02). It first checks,
     * before anything is locked or written, that the waitlist is switched on, that the day has not passed and that the service and
     * the collaborator are of the barbershop. Then, in one transaction, it creates the customer role of the person if they have
     * none (UP-09), takes the lock of their line, and refuses the row when they already have the most active rows (LE-01), already
     * wait for the same combination or when the combination has free spaces (the customer books one instead, and the error shows
     * them). The lock is taken before the count, so two requests at once with two rows cannot leave four. If anything fails the
     * transaction is rolled back and no membership is left. The row takes the instant of the request as its place in the line (LE-02).
     *
     * @throws WaitlistDisabledException
     * @throws DesiredDateInPastException
     * @throws ServiceNotFoundException
     * @throws CollaboratorNotFoundException
     * @throws WaitlistLimitReachedException
     * @throws DuplicateEntryException
     * @throws SpacesAvailableException
     */
    public function execute(JoinWaitlistCommand $command, DateTimeImmutable $now): WaitlistEntry
    {
        if (! $this->settings->isEnabled()) {
            throw WaitlistDisabledException::create();
        }

        $date = DesiredDate::upcoming($command->date, $now);

        if (! $this->catalog->hasService($command->serviceId)) {
            throw ServiceNotFoundException::create();
        }

        if ($command->collaboratorId !== null && ! $this->catalog->hasCollaborator($command->collaboratorId)) {
            throw CollaboratorNotFoundException::create();
        }

        $service = new ServiceId($command->serviceId);
        $collaborator = $command->collaboratorId === null ? null : new CollaboratorId($command->collaboratorId);

        return $this->transaction->run(function () use ($command, $date, $service, $collaborator, $now): WaitlistEntry {
            $customer = new CustomerMembershipId($this->customers->ensure($command->userId));
            $this->entries->lockCustomer($customer);

            if ($this->entries->countActiveOfCustomer($customer) >= WaitlistEntry::MAX_ACTIVE_PER_CUSTOMER) {
                throw WaitlistLimitReachedException::create();
            }

            if ($this->entries->hasActiveFor($customer, $service, $collaborator, $date)) {
                throw DuplicateEntryException::create();
            }

            $open = $this->spaces->on($command->serviceId, $date->value, $command->collaboratorId, $now);

            if ($open !== []) {
                throw SpacesAvailableException::with($open);
            }

            $entry = WaitlistEntry::join($this->entries->nextId(), $customer, $service, $collaborator, $date, $now);
            $this->entries->save($entry);

            return $entry;
        });
    }
}
