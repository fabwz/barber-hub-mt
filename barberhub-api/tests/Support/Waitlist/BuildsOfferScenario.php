<?php

declare(strict_types=1);

namespace Tests\Support\Waitlist;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Waitlist\Entries\Application\UseCases\ListWaitingForSpace;
use Src\Waitlist\Entries\Application\UseCases\LockWaitingEntry;
use Src\Waitlist\Entries\Application\UseCases\MarkEntryOffered;
use Src\Waitlist\Entries\Application\UseCases\ReturnEntryToLine;
use Src\Waitlist\Entries\Domain\Entities\WaitlistEntry;
use Src\Waitlist\Entries\Domain\ValueObjects\CollaboratorId as EntryCollaboratorId;
use Src\Waitlist\Entries\Domain\ValueObjects\CustomerMembershipId as EntryCustomerId;
use Src\Waitlist\Entries\Domain\ValueObjects\DesiredDate;
use Src\Waitlist\Entries\Domain\ValueObjects\ServiceId as EntryServiceId;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistEntryId;
use Src\Waitlist\Offers\Application\Contracts\WaitingLine;
use Src\Waitlist\Offers\Application\UseCases\ExpireOffer;
use Src\Waitlist\Offers\Application\UseCases\OfferReleasedSpace;
use Src\Waitlist\Offers\Domain\Entities\Offer;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferId;
use Src\Waitlist\Offers\Infrastructure\Entries\EntriesWaitingLine;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\RecordingDomainEvents;
use Tests\Support\Shared\RecordingTransactionRunner;

/**
 * The line, the offers and their ports wired in memory, for the unit tests of the offers. The line is the real one: the use cases of the Entries
 * module over an in-memory repository, so the order, the states and the locks are the ones of the product. Two services (30 and 60 minutes,
 * 35 and 65 with the cleaning), two collaborators and three customers, on Tuesday 5 March 2030, with the clock on the Friday before at 8:00.
 */
trait BuildsOfferScenario
{
    private const string BARBERSHOP = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d10';

    private const string DANIELA = '0192f0c4-5555-7000-8000-000000000001';

    private const string KEVIN = '0192f0c4-5555-7000-8000-000000000002';

    private const string CORTE = '0192f0c4-4444-7000-8000-000000000001';

    private const string DEGRADADO = '0192f0c4-4444-7000-8000-000000000002';

    private const string ANA = '0192f0c4-2222-7000-8000-000000000001';

    private const string LUIS = '0192f0c4-2222-7000-8000-000000000002';

    private const string BEA = '0192f0c4-2222-7000-8000-000000000003';

    private InMemoryWaitlistEntryRepository $entries;

    private InMemoryOfferRepository $offers;

    private OfferPorts $ports;

    private RecordingDomainEvents $events;

    private RecordingTransactionRunner $transaction;

    private OfferReleasedSpace $offerSpace;

    private ExpireOffer $expireOffer;

    /** Build fresh in-memory parts and wire the use cases over the real line; Daniela offers both services. */
    private function buildOfferScenario(): void
    {
        $this->entries = new InMemoryWaitlistEntryRepository;
        $this->offers = new InMemoryOfferRepository;
        $this->ports = new OfferPorts($this->offers);
        $this->ports->minutes = [self::CORTE => 35, self::DEGRADADO => 65];
        $this->events = new RecordingDomainEvents;
        $this->transaction = new RecordingTransactionRunner;
        $this->wireUseCases($this->realLine());
    }

    /** Wire the use cases of the offers over a line: the real one, or one that misbehaves in the way a test needs. */
    private function wireUseCases(WaitingLine $line): void
    {
        $context = new FixedBarbershopContext(new BarbershopId(self::BARBERSHOP));
        $this->offerSpace = new OfferReleasedSpace(
            $this->offers,
            $line,
            $this->ports,
            $this->ports,
            $this->ports,
            $this->ports,
            $this->ports,
            $this->ports,
            $this->transaction,
            $this->events,
            $context,
        );
        $this->expireOffer = new ExpireOffer($this->offers, $line, $this->ports, $this->offerSpace, $this->transaction, $this->events, $context);
    }

    /** The line as the offers see it, over the same in-memory rows. */
    private function realLine(): WaitingLine
    {
        return new EntriesWaitingLine(
            new ListWaitingForSpace($this->entries),
            new LockWaitingEntry($this->entries),
            new MarkEntryOffered($this->entries),
            new ReturnEntryToLine($this->entries),
        );
    }

    /** Put a customer in the line on 1 March at the time, for a service on a day, with a collaborator or with any. */
    private function wait(string $customer, string $service, string $joinedAt, string $date = '2030-03-05', ?string $collaborator = null): WaitlistEntry
    {
        $entry = WaitlistEntry::join(
            $this->entries->nextId(),
            new EntryCustomerId($customer),
            new EntryServiceId($service),
            $collaborator === null ? null : new EntryCollaboratorId($collaborator),
            DesiredDate::of($date),
            self::friday($joinedAt),
        );
        $this->entries->save($entry);

        return $entry;
    }

    /** An offer of the scenario, which must exist. */
    private function offerOf(OfferId $id): Offer
    {
        return $this->offers->find($id) ?? throw new LogicException('The offer is gone.');
    }

    /** The state of a row of the line now. */
    private function statusOf(WaitlistEntry $entry): string
    {
        return ($this->entries->findForUpdate(new WaitlistEntryId($entry->id->value)) ?? throw new LogicException('The row is gone.'))->status->value;
    }

    /** An instant on Friday 1 March 2030 in Costa Rica. */
    private static function friday(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2030-03-01 {$time}", new DateTimeZone('America/Costa_Rica'));
    }

    /** An instant on Tuesday 5 March 2030 in Costa Rica. */
    private static function tuesday(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2030-03-05 {$time}", new DateTimeZone('America/Costa_Rica'));
    }
}
