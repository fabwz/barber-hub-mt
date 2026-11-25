<?php

declare(strict_types=1);

namespace Tests\Unit\Waitlist\Offers\Application;

use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Domain\Entities\Offer;
use Src\Waitlist\Offers\Domain\Events\OfferCreated;
use Src\Waitlist\Offers\Domain\Events\OfferExpired;
use Src\Waitlist\Offers\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferStatus;
use Src\Waitlist\Offers\Domain\ValueObjects\WaitlistEntryId;
use Tests\Support\Waitlist\BuildsOfferScenario;

/**
 * What happens when the 15 minutes of an offer run out, without a framework or a database: the offer expires, its person goes back to the line,
 * and the same space passes to the next compatible person, at most a minute later; if nobody is left it is public again (RF-31, LE-04, LE-05).
 * It covers the cases of #134 about the expiry.
 */
final class ExpireOfferTest extends TestCase
{
    use BuildsOfferScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildOfferScenario();
    }

    /** Once it runs out the offer expires, and the same space is offered to the next compatible person in the line (LE-05). */
    public function test_an_expired_offer_passes_to_the_next_compatible_person(): void
    {
        $ana = $this->wait(self::ANA, self::CORTE, '08:00');
        $luis = $this->wait(self::LUIS, self::CORTE, '08:01');
        $first = $this->release();

        $expired = $this->expireOffer->execute($first->id->value, self::friday('08:15'));

        $this->assertTrue($expired);
        $this->assertSame(OfferStatus::Expired, $this->offerOf($first->id)->status);
        $this->assertNull($this->offerOf($first->id)->respondedAt);
        $second = $this->offers->all()[1];
        $this->assertSame($luis->id->value, $second->entryId->value);
        $this->assertSame(OfferStatus::Pending, $second->status);
        $this->assertEquals($first->interval, $second->interval, 'The same space goes on.');
        $this->assertEquals(self::friday('08:30'), $second->expiresAt, 'The 15 minutes of the new offer count from when it is made.');
        $this->assertSame('offered', $this->statusOf($luis));
        $this->assertSame('waiting', $this->statusOf($ana), 'The person who let it expire goes back to the line with their place.');
    }

    /** The person who let the offer expire is not offered the same space again, even though they wait again (LE-05). */
    public function test_the_person_who_let_it_expire_is_not_offered_the_same_space_again(): void
    {
        $ana = $this->wait(self::ANA, self::CORTE, '08:00');
        $first = $this->release();

        $this->expireOffer->execute($first->id->value, self::friday('08:15'));

        $this->assertCount(1, $this->offers->all(), 'Nobody else waits, so no new offer is made.');
        $this->assertSame('waiting', $this->statusOf($ana));
    }

    /** With three people and none answering, the offer goes down the line one by one, each in turn and none twice (RF-31). */
    public function test_the_offer_goes_down_the_line_one_by_one(): void
    {
        $ana = $this->wait(self::ANA, self::CORTE, '08:00');
        $luis = $this->wait(self::LUIS, self::CORTE, '08:01');
        $bea = $this->wait(self::BEA, self::CORTE, '08:02');
        $first = $this->release();
        $this->expireOffer->execute($first->id->value, self::friday('08:15'));
        $second = $this->offers->all()[1];
        $this->expireOffer->execute($second->id->value, self::friday('08:30'));
        $third = $this->offers->all()[2];
        $this->expireOffer->execute($third->id->value, self::friday('08:45'));

        $this->assertSame([$ana->id->value, $luis->id->value, $bea->id->value], array_map(fn (Offer $offer): string => $offer->entryId->value, $this->offers->all()));
        $this->assertCount(3, $this->offers->all(), 'The line ran out after the third: nobody is offered it twice.');
        $this->assertSame([OfferStatus::Expired, OfferStatus::Expired, OfferStatus::Expired], array_map(fn (Offer $offer): OfferStatus => $offer->status, $this->offers->all()));
        $this->assertSame(['waiting', 'waiting', 'waiting'], [$this->statusOf($ana), $this->statusOf($luis), $this->statusOf($bea)]);
    }

    /** When the line runs out, the space is public again: no offer holds it and the availability sees it free (RF-31, DI-05). */
    public function test_when_the_line_runs_out_the_space_is_public_again(): void
    {
        $this->wait(self::ANA, self::CORTE, '08:00');
        $offer = $this->release();
        $this->assertFalse($this->ports->isFree(self::CORTE, self::DANIELA, $offer->interval, self::friday('08:14')), 'It is held while the offer lives.');

        $this->expireOffer->execute($offer->id->value, self::friday('08:15'));

        $this->assertTrue($this->ports->isFree(self::CORTE, self::DANIELA, $offer->interval, self::friday('08:15')));
        $this->assertCount(1, $this->offers->all());
    }

    /** The line moves on up to a minute after the offer runs out, and at the very second it does (LE-05). */
    public function test_the_line_moves_on_within_a_minute_of_the_expiry(): void
    {
        foreach (['08:15:00', '08:15:01', '08:15:30', '08:16:00'] as $at) {
            $this->buildOfferScenario();
            $this->wait(self::ANA, self::CORTE, '08:00');
            $luis = $this->wait(self::LUIS, self::CORTE, '08:01');
            $first = $this->release();

            $this->assertTrue($this->expireOffer->execute($first->id->value, self::friday($at)), "At {$at} the offer was not expired.");
            $this->assertSame($luis->id->value, $this->offers->all()[1]->entryId->value, "At {$at} the line did not move on.");
        }
    }

    /** The task is left for the instant the offer runs out, not later, so the queue only adds the second it waits (LE-04, LE-05). */
    public function test_the_task_is_left_for_the_deadline_of_the_offer(): void
    {
        $this->wait(self::ANA, self::CORTE, '08:00');

        $offer = $this->release();

        $this->assertCount(1, $this->ports->deadlines);
        $this->assertSame($offer->id->value, $this->ports->deadlines[0][0]);
        $this->assertEquals(self::friday('08:15'), $this->ports->deadlines[0][1]);
    }

    /** An early task changes nothing: the offer keeps holding its space until its 15 minutes pass (LE-04). */
    public function test_an_early_task_changes_nothing(): void
    {
        $ana = $this->wait(self::ANA, self::CORTE, '08:00');
        $this->wait(self::LUIS, self::CORTE, '08:01');
        $offer = $this->release();

        $this->assertFalse($this->expireOffer->execute($offer->id->value, self::friday('08:14:59')));

        $this->assertSame(OfferStatus::Pending, $this->offerOf($offer->id)->status);
        $this->assertSame('offered', $this->statusOf($ana));
        $this->assertCount(1, $this->offers->all());
    }

    /** Running the task twice, as a retry of the queue would, expires the offer and passes the space once (RNF-09). */
    public function test_running_the_task_twice_passes_the_space_once(): void
    {
        $this->wait(self::ANA, self::CORTE, '08:00');
        $this->wait(self::LUIS, self::CORTE, '08:01');
        $this->wait(self::BEA, self::CORTE, '08:02');
        $first = $this->release();

        $this->assertTrue($this->expireOffer->execute($first->id->value, self::friday('08:15')));
        $this->assertFalse($this->expireOffer->execute($first->id->value, self::friday('08:16')));

        $this->assertCount(2, $this->offers->all());
        $this->assertCount(1, $this->events->of(OfferExpired::class));
        $this->assertCount(2, $this->events->of(OfferCreated::class));
    }

    /** An offer the person answered is left alone, and so is one that does not exist. */
    public function test_an_answered_or_unknown_offer_is_left_alone(): void
    {
        $ana = $this->wait(self::ANA, self::CORTE, '08:00');
        $accepted = Offer::reconstitute(
            $this->offers->nextId(),
            new WaitlistEntryId($ana->id->value),
            new CollaboratorId(self::DANIELA),
            TimeInterval::between(self::tuesday('13:00'), self::tuesday('14:05')),
            self::friday('08:15'),
            OfferStatus::Accepted,
            self::friday('08:05'),
            self::friday('08:00'),
        );
        $this->offers->save($accepted);

        $this->assertFalse($this->expireOffer->execute($accepted->id->value, self::friday('09:00')));
        $this->assertFalse($this->expireOffer->execute('0192f0c4-6666-7000-8000-00000000ffff', self::friday('09:00')));
        $this->assertSame(OfferStatus::Accepted, $this->offerOf($accepted->id)->status);
        $this->assertSame([], $this->events->all());
    }

    /** The collaborator is locked before the offer is expired, as every operation on their time does (RE-01). */
    public function test_the_collaborator_is_locked_first(): void
    {
        $this->wait(self::ANA, self::CORTE, '08:00');
        $first = $this->release();
        $this->ports->forgetCalls();

        $this->expireOffer->execute($first->id->value, self::friday('08:15'));

        $this->assertSame(['lock'], array_slice($this->ports->calls, 0, 1));
    }

    /** The expiry is announced once, with the row it was for, so the pop-up disappears (RF-35). */
    public function test_the_expiry_is_announced(): void
    {
        $ana = $this->wait(self::ANA, self::CORTE, '08:00');
        $first = $this->release();

        $this->expireOffer->execute($first->id->value, self::friday('08:15'));

        $expired = $this->events->of(OfferExpired::class);
        $this->assertCount(1, $expired);
        $this->assertSame(self::BARBERSHOP, $expired[0]->barbershopId);
        $this->assertSame($first->id->value, $expired[0]->offerId);
        $this->assertSame($ana->id->value, $expired[0]->waitlistEntryId);
        $this->assertSame(self::ANA, $expired[0]->customerMembershipId);
    }

    /** If fewer than 60 minutes remain when the offer runs out, the next person is not offered the space and it is public again (LE-03). */
    public function test_with_fewer_than_sixty_minutes_left_the_space_is_not_passed_on(): void
    {
        $this->wait(self::ANA, self::CORTE, '08:00');
        $luis = $this->wait(self::LUIS, self::CORTE, '08:01');
        $first = $this->offerSpace->execute(self::DANIELA, self::tuesday('13:00'), self::tuesday('14:05'), self::tuesday('11:50'));
        $this->assertNotNull($first);

        $this->expireOffer->execute($first->id->value, self::tuesday('12:06'));

        $this->assertCount(1, $this->offers->all());
        $this->assertSame('waiting', $this->statusOf($luis));
    }

    /** If the waitlist was switched off meanwhile, the offer in course still expires, but no new offer is made (RF-09). */
    public function test_with_the_waitlist_off_the_offer_expires_and_nothing_new_is_made(): void
    {
        $this->wait(self::ANA, self::CORTE, '08:00');
        $luis = $this->wait(self::LUIS, self::CORTE, '08:01');
        $first = $this->release();
        $this->ports->enabled = false;

        $this->assertTrue($this->expireOffer->execute($first->id->value, self::friday('08:15')));

        $this->assertSame(OfferStatus::Expired, $this->offerOf($first->id)->status);
        $this->assertCount(1, $this->offers->all());
        $this->assertSame('waiting', $this->statusOf($luis));
    }

    /** A person who left the line while they had the offer stays out, and the space goes to the next one. */
    public function test_a_person_who_left_meanwhile_does_not_come_back(): void
    {
        $ana = $this->wait(self::ANA, self::CORTE, '08:00');
        $luis = $this->wait(self::LUIS, self::CORTE, '08:01');
        $first = $this->release();
        $row = $this->entries->findForUpdate(new \Src\Waitlist\Entries\Domain\ValueObjects\WaitlistEntryId($ana->id->value)) ?? $this->fail('The row is gone.');
        $row->leave(self::friday('08:10'));
        $this->entries->save($row);

        $this->expireOffer->execute($first->id->value, self::friday('08:15'));

        $this->assertSame('left', $this->statusOf($ana));
        $this->assertSame($luis->id->value, $this->offers->all()[1]->entryId->value);
    }

    /** Someone who asked for another collaborator is skipped when the offer passes on, and the next who asked for any receives it (LE-01, LE-05). */
    public function test_the_next_person_is_the_next_compatible_one_not_just_the_next_in_line(): void
    {
        $this->wait(self::ANA, self::CORTE, '08:00');
        $this->wait(self::LUIS, self::CORTE, '08:01', collaborator: self::KEVIN);
        $bea = $this->wait(self::BEA, self::CORTE, '08:02');
        $first = $this->release();

        $this->expireOffer->execute($first->id->value, self::friday('08:15'));

        $this->assertSame($bea->id->value, $this->offers->all()[1]->entryId->value);
    }

    /** Make the first offer of the scenario: Daniela frees 13:00 to 14:05 on Tuesday, at 8:00 on Friday. */
    private function release(): Offer
    {
        return $this->offerSpace->execute(self::DANIELA, self::tuesday('13:00'), self::tuesday('14:05'), self::friday('08:00')) ?? $this->fail('The space was not offered.');
    }
}
