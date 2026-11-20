<?php

declare(strict_types=1);

namespace Tests\Unit\Waitlist\Offers\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Domain\Entities\Offer;
use Src\Waitlist\Offers\Domain\Exceptions\OfferAlreadyAnsweredException;
use Src\Waitlist\Offers\Domain\Exceptions\OfferExpiredException;
use Src\Waitlist\Offers\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferId;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferStatus;
use Src\Waitlist\Offers\Domain\ValueObjects\ReservationId;
use Src\Waitlist\Offers\Domain\ValueObjects\WaitlistEntryId;

/** The answers to an offer, without a database: the customer accepts or turns it down inside its 15 minutes, and never after (RF-32, LE-04, LE-05). */
final class OfferAnswerTest extends TestCase
{
    /** Accepting a pending offer inside its time marks it accepted with the instant of the answer (RF-32). */
    public function test_accepting_marks_the_offer_and_records_the_instant(): void
    {
        $offer = self::offer();

        $offer->accept(self::at('11:05'));

        $this->assertSame(OfferStatus::Accepted, $offer->status);
        $this->assertEquals(self::at('11:05'), $offer->respondedAt);
        $this->assertFalse($offer->isPending());
    }

    /** Turning an offer down marks it rejected with the instant of the answer (RF-32). */
    public function test_rejecting_marks_the_offer_and_records_the_instant(): void
    {
        $offer = self::offer();

        $offer->reject(self::at('11:05'));

        $this->assertSame(OfferStatus::Rejected, $offer->status);
        $this->assertEquals(self::at('11:05'), $offer->respondedAt);
    }

    /** The last instant to answer is the one before the 15 minutes pass; at the deadline the offer is lost (LE-04). */
    public function test_it_can_be_answered_up_to_the_instant_before_the_deadline(): void
    {
        $accepted = self::offer();
        $accepted->accept(self::at('11:14:59'));
        $rejected = self::offer();
        $rejected->reject(self::at('11:14:59'));

        $this->assertSame(OfferStatus::Accepted, $accepted->status);
        $this->assertSame(OfferStatus::Rejected, $rejected->status);
    }

    /** At the deadline, and after it, both answers are `oferta-vencida`, whether or not the task of the queue marked the offer yet (LE-04, LE-05). */
    public function test_an_answer_at_or_after_the_deadline_is_refused_as_expired(): void
    {
        foreach (['11:15', '11:15:01', '12:00'] as $moment) {
            $this->assertExpired(fn () => self::offer()->accept(self::at($moment)));
            $this->assertExpired(fn () => self::offer()->reject(self::at($moment)));
        }
    }

    /** An offer the task already marked expired is lost for both answers, even if the clock of the caller is early (LE-05). */
    public function test_an_expired_offer_cannot_be_answered(): void
    {
        $offer = self::offer();
        $offer->expire(self::at('11:15'));

        $this->assertExpired(fn () => $offer->accept(self::at('11:01')));
        $this->assertExpired(fn () => $offer->reject(self::at('11:01')));
        $this->assertSame(OfferStatus::Expired, $offer->status);
        $this->assertNull($offer->respondedAt);
    }

    /** Answering the same way twice changes nothing, so a repeated press or a retry is harmless (RF-32). */
    public function test_the_same_answer_twice_changes_nothing(): void
    {
        $accepted = self::offer();
        $accepted->accept(self::at('11:05'));
        $accepted->accept(self::at('11:09'));
        $rejected = self::offer();
        $rejected->reject(self::at('11:05'));
        $rejected->reject(self::at('11:09'));

        $this->assertEquals(self::at('11:05'), $accepted->respondedAt);
        $this->assertEquals(self::at('11:05'), $rejected->respondedAt);
    }

    /** The opposite answer to the one the offer already has is refused: an accepted offer is not rejected and a rejected one is not accepted. */
    public function test_the_opposite_answer_is_refused(): void
    {
        $accepted = self::offer();
        $accepted->accept(self::at('11:05'));

        $this->expectException(OfferAlreadyAnsweredException::class);
        $accepted->reject(self::at('11:06'));
    }

    /** A rejected offer is not accepted afterwards. */
    public function test_a_rejected_offer_cannot_be_accepted(): void
    {
        $rejected = self::offer();
        $rejected->reject(self::at('11:05'));

        $this->expectException(OfferAlreadyAnsweredException::class);
        $rejected->accept(self::at('11:06'));
    }

    /** The error of an answer after the deadline has the stable type of the API (LE-05). */
    public function test_the_expired_error_has_its_stable_type(): void
    {
        $this->assertSame('oferta-vencida', OfferExpiredException::create()->rule());
        $this->assertSame('conflicto', OfferAlreadyAnsweredException::create()->rule());
    }

    /** An open offer passes the check; a lost one, or one already answered, does not. */
    public function test_the_open_check_tells_pending_inside_its_time_from_the_rest(): void
    {
        self::offer()->assertOpenAt(self::at('11:14:59'));
        $this->assertExpired(fn () => self::offer()->assertOpenAt(self::at('11:15')));

        $accepted = self::offer();
        $accepted->accept(self::at('11:05'));
        $this->expectException(OfferAlreadyAnsweredException::class);
        $accepted->assertOpenAt(self::at('11:06'));
    }

    /** The offer keeps the reservation booked for it, which a stored offer brings back (RF-32). */
    public function test_the_offer_keeps_the_reservation_that_answers_it(): void
    {
        $offer = self::offer();
        $this->assertNull($offer->reservationId);

        $offer->answerWith(new ReservationId('0192f3a0-0000-7000-8000-000000000009'));

        $this->assertSame('0192f3a0-0000-7000-8000-000000000009', $offer->reservationId?->value);
        $this->assertSame(OfferStatus::Pending, $offer->status);
        $this->assertTrue($offer->holdsSpaceAt(self::at('11:05')));
    }

    /** @param callable(): mixed $answer */
    private function assertExpired(callable $answer): void
    {
        try {
            $answer();
            $this->fail('The answer was accepted; it should be refused as expired.');
        } catch (OfferExpiredException $exception) {
            $this->assertSame('oferta-vencida', $exception->rule());
        }
    }

    /** A pending offer made at 11:00, so it expires at 11:15. */
    private static function offer(): Offer
    {
        return Offer::create(
            new OfferId('0192f3a0-0000-7000-8000-000000000001'),
            new WaitlistEntryId('0192f3a0-0000-7000-8000-000000000002'),
            new CollaboratorId('0192f3a0-0000-7000-8000-000000000003'),
            TimeInterval::between(
                new DateTimeImmutable('2030-03-05 13:00:00', new DateTimeZone('America/Costa_Rica')),
                new DateTimeImmutable('2030-03-05 14:05:00', new DateTimeZone('America/Costa_Rica')),
            ),
            self::at('11:00'),
        );
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2030-03-05 {$time}", new DateTimeZone('America/Costa_Rica'));
    }
}
