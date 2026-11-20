<?php

declare(strict_types=1);

namespace Tests\Unit\Waitlist\Offers\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Domain\Entities\Offer;
use Src\Waitlist\Offers\Domain\Exceptions\OfferNotExpirableException;
use Src\Waitlist\Offers\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferId;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferStatus;
use Src\Waitlist\Offers\Domain\ValueObjects\WaitlistEntryId;

/** The rules of an offer, without a database: it holds a space for 15 minutes and expires on its own (LE-04, LE-05, DI-05). */
final class OfferTest extends TestCase
{
    /** A new offer is pending, has no answer and keeps what it offers (LE-04). */
    public function test_a_new_offer_is_pending_and_keeps_its_space(): void
    {
        $offer = self::offer(self::at('11:00'));

        $this->assertSame(OfferStatus::Pending, $offer->status);
        $this->assertNull($offer->respondedAt);
        $this->assertTrue($offer->isPending());
        $this->assertSame('2030-03-05 13:00', $offer->interval->start->format('Y-m-d H:i'));
        $this->assertSame('2030-03-05 14:05', $offer->interval->end->format('Y-m-d H:i'));
    }

    /** It lasts exactly 15 minutes from the instant it is made (LE-04). */
    public function test_it_lasts_fifteen_minutes_from_its_creation(): void
    {
        $offer = self::offer(self::at('11:00'));

        $this->assertSame(15, Offer::LIFETIME_MINUTES);
        $this->assertEquals(self::at('11:15'), $offer->expiresAt);
        $this->assertEquals(self::at('11:00'), $offer->createdAt);
    }

    /** It holds its space up to the instant before the deadline and releases it at the deadline, as a temporary reservation does (DI-05). */
    public function test_it_holds_its_space_until_the_deadline(): void
    {
        $offer = self::offer(self::at('11:00'));

        $this->assertTrue($offer->holdsSpaceAt(self::at('11:00')));
        $this->assertTrue($offer->holdsSpaceAt(self::at('11:14:59')));
        $this->assertFalse($offer->holdsSpaceAt(self::at('11:15')));
        $this->assertFalse($offer->isOverdueAt(self::at('11:14:59')));
        $this->assertTrue($offer->isOverdueAt(self::at('11:15')));
    }

    /** After the deadline it expires, even though nobody answered, and it stops holding the space (LE-05). */
    public function test_it_expires_after_its_deadline(): void
    {
        $offer = self::offer(self::at('11:00'));

        $offer->expire(self::at('11:15'));

        $this->assertSame(OfferStatus::Expired, $offer->status);
        $this->assertNull($offer->respondedAt);
        $this->assertFalse($offer->isPending());
        $this->assertFalse($offer->holdsSpaceAt(self::at('11:00')));
    }

    /** Expiring twice changes nothing, so a retried task is harmless. */
    public function test_expiring_twice_changes_nothing(): void
    {
        $offer = self::offer(self::at('11:00'));
        $offer->expire(self::at('11:16'));

        $offer->expire(self::at('11:30'));

        $this->assertSame(OfferStatus::Expired, $offer->status);
    }

    /** An offer still inside its 15 minutes cannot expire. */
    public function test_it_cannot_expire_before_its_deadline(): void
    {
        $offer = self::offer(self::at('11:00'));

        $this->expectException(OfferNotExpirableException::class);

        $offer->expire(self::at('11:14:59'));
    }

    /** An offer the person already answered cannot expire. */
    public function test_an_answered_offer_cannot_expire(): void
    {
        foreach ([OfferStatus::Accepted, OfferStatus::Rejected] as $status) {
            $offer = Offer::reconstitute(
                new OfferId('0192f3a0-0000-7000-8000-000000000001'),
                new WaitlistEntryId('0192f3a0-0000-7000-8000-000000000002'),
                new CollaboratorId('0192f3a0-0000-7000-8000-000000000003'),
                self::space(),
                self::at('11:15'),
                $status,
                self::at('11:05'),
                self::at('11:00'),
            );

            try {
                $offer->expire(self::at('12:00'));
                $this->fail('An answered offer expired.');
            } catch (OfferNotExpirableException) {
                $this->assertSame($status, $offer->status);
            }
        }
    }

    private static function offer(DateTimeImmutable $at): Offer
    {
        return Offer::create(
            new OfferId('0192f3a0-0000-7000-8000-000000000001'),
            new WaitlistEntryId('0192f3a0-0000-7000-8000-000000000002'),
            new CollaboratorId('0192f3a0-0000-7000-8000-000000000003'),
            self::space(),
            $at,
        );
    }

    /** The interval the freed reservation held: an hour of service and five minutes of cleaning. */
    private static function space(): TimeInterval
    {
        return TimeInterval::between(
            new DateTimeImmutable('2030-03-05 13:00:00', new DateTimeZone('America/Costa_Rica')),
            new DateTimeImmutable('2030-03-05 14:05:00', new DateTimeZone('America/Costa_Rica')),
        );
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2030-03-05 {$time}", new DateTimeZone('America/Costa_Rica'));
    }
}
