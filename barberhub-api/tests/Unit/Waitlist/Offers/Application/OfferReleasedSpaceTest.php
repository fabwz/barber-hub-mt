<?php

declare(strict_types=1);

namespace Tests\Unit\Waitlist\Offers\Application;

use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Domain\Entities\Offer;
use Src\Waitlist\Offers\Domain\Events\OfferCreated;
use Src\Waitlist\Offers\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferStatus;
use Src\Waitlist\Offers\Domain\ValueObjects\WaitlistEntryId;
use Tests\Support\Waitlist\BuildsOfferScenario;
use Tests\Support\Waitlist\RefusingWaitingLine;

/**
 * Who receives a space that was freed, without a framework or a database: the first compatible person of the line, and nobody when fewer than
 * 60 minutes remain, when the hole is too small, when it is not free or when a rule of the person forbids it (RF-31, LE-02 to LE-06, DI-05).
 * It covers the cases of #134 about the order, the compatibility and the notice.
 */
final class OfferReleasedSpaceTest extends TestCase
{
    use BuildsOfferScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildOfferScenario();
    }

    /** With three people in the line for the same service, the offer goes to the first one only, and the other two keep waiting (RF-31, LE-02). */
    public function test_with_three_people_in_the_line_the_offer_goes_only_to_the_first(): void
    {
        $ana = $this->wait(self::ANA, self::CORTE, '08:00');
        $luis = $this->wait(self::LUIS, self::CORTE, '08:01');
        $bea = $this->wait(self::BEA, self::CORTE, '08:02');

        $offer = $this->release('13:00', '14:05');

        $this->assertNotNull($offer);
        $this->assertSame($ana->id->value, $offer->entryId->value);
        $this->assertSame('offered', $this->statusOf($ana));
        $this->assertSame('waiting', $this->statusOf($luis));
        $this->assertSame('waiting', $this->statusOf($bea));
        $this->assertCount(1, $this->offers->all());
    }

    /** The order is the order of joining, whatever the order the rows were written in (LE-02). */
    public function test_the_order_is_the_order_of_joining(): void
    {
        $this->wait(self::BEA, self::CORTE, '08:02');
        $ana = $this->wait(self::ANA, self::CORTE, '07:00');
        $this->wait(self::LUIS, self::CORTE, '08:01');

        $this->assertSame($ana->id->value, $this->release('13:00', '14:05')?->entryId->value);
    }

    /** The offer holds the whole space for 15 minutes from now, for the collaborator who freed it (LE-04, DI-05). */
    public function test_the_offer_holds_the_whole_space_for_fifteen_minutes(): void
    {
        $this->wait(self::ANA, self::CORTE, '08:00');

        $offer = $this->release('13:00', '14:05');

        $this->assertNotNull($offer);
        $this->assertSame(OfferStatus::Pending, $offer->status);
        $this->assertSame(self::DANIELA, $offer->collaboratorId->value);
        $this->assertEquals(self::tuesday('13:00'), $offer->interval->start);
        $this->assertEquals(self::tuesday('14:05'), $offer->interval->end);
        $this->assertEquals(self::friday('08:15'), $offer->expiresAt);
        $this->assertCount(1, $this->ports->deadlines, 'A task is left for the moment it runs out.');
        $this->assertSame($offer->id->value, $this->ports->deadlines[0][0]);
        $this->assertEquals($offer->expiresAt, $this->ports->deadlines[0][1]);
    }

    /** A hole of 30 minutes is not offered to someone who waits for 60, and passes to the next compatible person (RF-31, LE-03). */
    public function test_a_hole_of_thirty_minutes_skips_who_waits_for_sixty_and_goes_to_the_next_compatible(): void
    {
        $long = $this->wait(self::ANA, self::DEGRADADO, '08:00');
        $short = $this->wait(self::LUIS, self::CORTE, '08:01');

        $offer = $this->release('13:00', '13:35');

        $this->assertSame($short->id->value, $offer?->entryId->value);
        $this->assertSame('waiting', $this->statusOf($long), 'The person who waits for 60 minutes keeps their place.');
        $this->assertSame('offered', $this->statusOf($short));
    }

    /** If nobody who waits fits the hole, nothing is offered and the space stays public (RF-31, LE-05). */
    public function test_a_hole_that_fits_nobody_is_not_offered(): void
    {
        $long = $this->wait(self::ANA, self::DEGRADADO, '08:00');

        $this->assertNull($this->release('13:00', '13:35'));
        $this->assertSame('waiting', $this->statusOf($long));
        $this->assertSame([], $this->offers->all());
        $this->assertSame([], $this->events->all());
    }

    /** With fewer than 60 minutes before the space, nothing is offered, and nothing is locked or read (LE-03). */
    public function test_with_fewer_than_sixty_minutes_nothing_is_offered(): void
    {
        $ana = $this->wait(self::ANA, self::CORTE, '08:00');

        $offer = $this->offerSpace->execute(self::DANIELA, self::tuesday('13:00'), self::tuesday('14:05'), self::tuesday('12:01'));

        $this->assertNull($offer);
        $this->assertSame('waiting', $this->statusOf($ana));
        $this->assertSame([], $this->ports->calls, 'The collaborator was not even locked.');
        $this->assertSame(0, $this->transaction->runs());
    }

    /** With exactly 60 minutes it is still offered, and a second less is not (LE-03). */
    public function test_exactly_sixty_minutes_is_enough(): void
    {
        $this->wait(self::ANA, self::CORTE, '08:00');

        $this->assertNull($this->offerSpace->execute(self::DANIELA, self::tuesday('13:00'), self::tuesday('14:05'), self::tuesday('12:00:01')));
        $this->assertNotNull($this->offerSpace->execute(self::DANIELA, self::tuesday('13:00'), self::tuesday('14:05'), self::tuesday('12:00')));
    }

    /** With the waitlist switched off no new offer is made, and the row keeps waiting (RF-09). */
    public function test_with_the_waitlist_off_nothing_is_offered(): void
    {
        $ana = $this->wait(self::ANA, self::CORTE, '08:00');
        $this->ports->enabled = false;

        $this->assertNull($this->release('13:00', '14:05'));
        $this->assertSame('waiting', $this->statusOf($ana));
    }

    /** A person who waits for another day, or for another collaborator, is not compatible and is skipped (LE-01, LE-03). */
    public function test_another_day_or_another_collaborator_is_skipped(): void
    {
        $this->wait(self::ANA, self::CORTE, '08:00', '2030-03-06');
        $this->wait(self::LUIS, self::CORTE, '08:01', '2030-03-05', self::KEVIN);
        $bea = $this->wait(self::BEA, self::CORTE, '08:02', '2030-03-05', self::DANIELA);

        $this->assertSame($bea->id->value, $this->release('13:00', '14:05')?->entryId->value);
    }

    /** Someone who waits for a service the collaborator does not offer has no minutes and is skipped (CO-01). */
    public function test_a_service_the_collaborator_does_not_offer_is_skipped(): void
    {
        $this->ports->minutes = [self::CORTE => 35];
        $this->wait(self::ANA, self::DEGRADADO, '08:00');
        $luis = $this->wait(self::LUIS, self::CORTE, '08:01');

        $this->assertSame($luis->id->value, $this->release('13:00', '14:05')?->entryId->value);
    }

    /** If the space is not free for the service of a person, it goes to the next whose service is (DI-01, DI-05). */
    public function test_a_space_that_is_not_free_for_a_service_goes_to_the_next(): void
    {
        $this->ports->taken = [self::DEGRADADO];
        $this->wait(self::ANA, self::DEGRADADO, '08:00');
        $luis = $this->wait(self::LUIS, self::CORTE, '08:01');

        $this->assertSame($luis->id->value, $this->release('13:00', '14:05')?->entryId->value);
    }

    /** If somebody booked the space meanwhile, nothing is offered and nobody changes (RF-31). */
    public function test_a_space_that_was_taken_is_not_offered(): void
    {
        $ana = $this->wait(self::ANA, self::CORTE, '08:00');
        $this->ports->taken = [self::CORTE];

        $this->assertNull($this->release('13:00', '14:05'));
        $this->assertSame('waiting', $this->statusOf($ana));
    }

    /** A person who is not waiting anymore when the line holds their row, because it was offered or left meanwhile, is skipped (LE-06). */
    public function test_a_row_that_is_not_waiting_when_held_is_skipped(): void
    {
        $first = $this->wait(self::ANA, self::CORTE, '08:00');
        $second = $this->wait(self::LUIS, self::CORTE, '08:01');
        $this->wireUseCases(new RefusingWaitingLine($this->realLine(), $first->id->value));

        $offer = $this->release('13:00', '14:05');

        $this->assertSame($second->id->value, $offer?->entryId->value);
        $this->assertSame('waiting', $this->statusOf($first), 'The row that was gone is left as it was.');
    }

    /** A person who already had an offer for that space, and let it expire or turned it down, is not offered it again (LE-05). */
    public function test_a_person_who_already_had_the_space_is_not_offered_it_again(): void
    {
        $ana = $this->wait(self::ANA, self::CORTE, '08:00');
        $luis = $this->wait(self::LUIS, self::CORTE, '08:01');
        $before = Offer::create($this->offers->nextId(), new WaitlistEntryId($ana->id->value), new CollaboratorId(self::DANIELA), $this->space('13:00', '14:05'), self::friday('07:00'));
        $before->expire(self::friday('07:16'));
        $this->offers->save($before);

        $this->assertSame($luis->id->value, $this->release('13:00', '14:05')?->entryId->value);
        $this->assertSame('waiting', $this->statusOf($ana));
    }

    /** A person who holds a pending offer for another row that overlaps the space does not receive a second, overlapping one (LE-06). */
    public function test_a_person_never_receives_two_overlapping_offers(): void
    {
        $ana = $this->wait(self::ANA, self::CORTE, '08:00', '2030-03-05');
        $anaOther = $this->wait(self::ANA, self::DEGRADADO, '07:00', '2030-03-05', self::KEVIN);
        $luis = $this->wait(self::LUIS, self::CORTE, '08:01');
        $anaOther->offer(self::friday('08:00'));
        $this->entries->save($anaOther);
        $held = Offer::create($this->offers->nextId(), new WaitlistEntryId($anaOther->id->value), new CollaboratorId(self::KEVIN), $this->space('13:30', '14:35'), self::friday('08:00'));
        $this->offers->save($held);

        $offer = $this->release('13:00', '14:05');

        $this->assertSame($luis->id->value, $offer?->entryId->value, 'Ana already holds an offer that overlaps this space, in another row.');
        $this->assertSame('waiting', $this->statusOf($ana));
    }

    /** A person with a reservation of their own that overlaps the space is skipped (LE-03). */
    public function test_a_person_with_their_own_reservation_in_the_space_is_skipped(): void
    {
        $this->ports->busy = [self::ANA];
        $this->wait(self::ANA, self::CORTE, '08:00');
        $luis = $this->wait(self::LUIS, self::CORTE, '08:01');

        $this->assertSame($luis->id->value, $this->release('13:00', '14:05')?->entryId->value);
    }

    /** The offer is announced for the notices, once, with what they need and the barbershop; nothing else is sent here (RF-31, RF-34, RF-35). */
    public function test_the_offer_is_announced_once_with_its_barbershop(): void
    {
        $ana = $this->wait(self::ANA, self::CORTE, '08:00');

        $offer = $this->release('13:00', '14:05');

        $created = $this->events->of(OfferCreated::class);
        $this->assertCount(1, $created);
        $this->assertSame(self::BARBERSHOP, $created[0]->barbershopId);
        $this->assertSame($offer?->id->value, $created[0]->offerId);
        $this->assertSame($ana->id->value, $created[0]->waitlistEntryId);
        $this->assertSame(self::ANA, $created[0]->customerMembershipId);
        $this->assertSame(self::CORTE, $created[0]->serviceId);
        $this->assertSame(self::DANIELA, $created[0]->collaboratorId);
        $this->assertEquals(self::tuesday('13:00'), $created[0]->startsAt);
        $this->assertEquals(self::tuesday('14:05'), $created[0]->endsAt);
        $this->assertEquals(self::friday('08:15'), $created[0]->expiresAt);
        $this->assertCount(1, $this->events->all(), 'Nothing but the offer is announced.');
    }

    /** The collaborator is locked before the line is read or the space checked, as every operation on their time does (RE-01, DI-05). */
    public function test_the_collaborator_is_locked_before_the_space_is_checked(): void
    {
        $this->wait(self::ANA, self::CORTE, '08:00');

        $this->release('13:00', '14:05');

        $this->assertSame(['lock', 'free '.self::CORTE], $this->ports->calls);
        $this->assertSame(1, $this->transaction->committed());
    }

    /** Processing the same release twice does not duplicate the offer: the space is held by the first one (RF-31, DI-05). */
    public function test_processing_the_same_release_twice_offers_once(): void
    {
        $this->wait(self::ANA, self::CORTE, '08:00');
        $luis = $this->wait(self::LUIS, self::CORTE, '08:01');

        $first = $this->release('13:00', '14:05');
        $second = $this->release('13:00', '14:05');

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertCount(1, $this->offers->all());
        $this->assertSame('waiting', $this->statusOf($luis));
        $this->assertCount(1, $this->events->of(OfferCreated::class));
    }

    /** A collaborator that is not of the barbershop, or whose space cannot be locked, offers nothing. */
    public function test_a_collaborator_that_cannot_be_locked_offers_nothing(): void
    {
        $ana = $this->wait(self::ANA, self::CORTE, '08:00');
        $this->ports->collaboratorExists = false;

        $this->assertNull($this->release('13:00', '14:05'));
        $this->assertSame('waiting', $this->statusOf($ana));
    }

    /** With nobody in the line, nothing is offered. */
    public function test_an_empty_line_offers_nothing(): void
    {
        $this->assertNull($this->release('13:00', '14:05'));
        $this->assertSame([], $this->events->all());
    }

    /** Release the space of Daniela between the two times of Tuesday 5 March, on Friday at 8:00. */
    private function release(string $from, string $to): ?Offer
    {
        return $this->offerSpace->execute(self::DANIELA, self::tuesday($from), self::tuesday($to), self::friday('08:00'));
    }

    private function space(string $from, string $to): TimeInterval
    {
        return TimeInterval::between(self::tuesday($from), self::tuesday($to));
    }
}
