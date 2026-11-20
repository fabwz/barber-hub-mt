<?php

declare(strict_types=1);

namespace Tests\Unit\Waitlist\Offers\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Offers\Domain\ValueObjects\CustomerMembershipId;
use Src\Waitlist\Offers\Domain\ValueObjects\ReleasedSpace;
use Src\Waitlist\Offers\Domain\ValueObjects\ServiceId;
use Src\Waitlist\Offers\Domain\ValueObjects\WaitingCustomer;
use Src\Waitlist\Offers\Domain\ValueObjects\WaitlistEntryId;

/** Who can take a freed space and when it is still offered, without a database: LE-03, and the cases of #134 about the size of the hole and the notice. */
final class CompatibilityTest extends TestCase
{
    private const string DANIELA = '0192f3a0-0000-7000-8000-000000000001';

    private const string KEVIN = '0192f3a0-0000-7000-8000-000000000002';

    /** A hole of 30 minutes with its cleaning is 35: it fits a service of 30, and does not fit one of 60 (LE-03). */
    public function test_a_hole_of_thirty_minutes_does_not_fit_a_service_of_sixty(): void
    {
        $hole = self::space('13:00', '13:35');

        $this->assertTrue($hole->fits(35), 'A service of 30 minutes and its cleaning fit exactly.');
        $this->assertFalse($hole->fits(65), 'A service of 60 minutes and its cleaning do not.');
        $this->assertFalse(self::waiting(65)->isCompatibleWith($hole));
        $this->assertTrue(self::waiting(35)->isCompatibleWith($hole));
    }

    /** A hole bigger than the service fits it, and one a minute short does not. */
    public function test_the_service_fits_when_it_is_not_longer_than_the_hole(): void
    {
        $this->assertTrue(self::space('13:00', '14:05')->fits(65));
        $this->assertTrue(self::space('13:00', '14:05')->fits(35));
        $this->assertFalse(self::space('13:00', '14:04')->fits(65));
    }

    /** It is offered while 60 minutes or more remain before it starts, and it is not when fewer remain (LE-03). */
    public function test_it_is_offered_only_with_sixty_minutes_of_notice(): void
    {
        $space = self::space('13:00', '14:05');

        $this->assertTrue($space->isOfferableAt(self::at('12:00')), 'Exactly 60 minutes remain.');
        $this->assertTrue($space->isOfferableAt(self::at('08:00')));
        $this->assertFalse($space->isOfferableAt(self::at('12:00:01')), 'A second short of 60 minutes.');
        $this->assertFalse($space->isOfferableAt(self::at('12:30')));
        $this->assertFalse($space->isOfferableAt(self::at('13:00')), 'It already started.');
        $this->assertSame(60, ReleasedSpace::MIN_NOTICE_MINUTES);
    }

    /** The day of the space is the one in Costa Rica, whatever the zone its instants are written in. */
    public function test_the_day_of_the_space_is_read_in_costa_rica(): void
    {
        $utc = new ReleasedSpace(
            new CollaboratorId(self::DANIELA),
            TimeInterval::between(new DateTimeImmutable('2030-03-06 02:00:00', new DateTimeZone('UTC')), new DateTimeImmutable('2030-03-06 03:00:00', new DateTimeZone('UTC'))),
        );

        $this->assertSame('2030-03-05', $utc->date(), '02:00 UTC of the 6th is 20:00 of the 5th in Costa Rica.');
    }

    /** A person who waits for another day is not compatible. */
    public function test_a_person_who_waits_for_another_day_is_not_compatible(): void
    {
        $this->assertFalse(self::waiting(35, date: '2030-03-06')->isCompatibleWith(self::space('13:00', '14:05')));
    }

    /** Someone who asks for the collaborator who freed the space is compatible, someone who asks for another is not, and someone who asks for any is (LE-01, LE-03). */
    public function test_the_collaborator_asked_for_is_the_one_that_freed_the_space_or_any(): void
    {
        $space = self::space('13:00', '14:05');

        $this->assertTrue(self::waiting(35, collaborator: self::DANIELA)->isCompatibleWith($space));
        $this->assertFalse(self::waiting(35, collaborator: self::KEVIN)->isCompatibleWith($space));
        $this->assertTrue(self::waiting(35, collaborator: null)->isCompatibleWith($space));
    }

    /** Compatibility needs all of it at once: day, collaborator and size. */
    public function test_compatibility_needs_day_collaborator_and_size_together(): void
    {
        $space = self::space('13:00', '13:35');

        $this->assertFalse(self::waiting(65, collaborator: self::DANIELA)->isCompatibleWith($space));
        $this->assertFalse(self::waiting(35, date: '2030-03-04', collaborator: self::DANIELA)->isCompatibleWith($space));
        $this->assertFalse(self::waiting(35, collaborator: self::KEVIN)->isCompatibleWith($space));
        $this->assertTrue(self::waiting(35, collaborator: self::DANIELA)->isCompatibleWith($space));
    }

    private static function waiting(int $minutes, string $date = '2030-03-05', ?string $collaborator = null): WaitingCustomer
    {
        $entry = new WaitlistEntryId('0192f3a0-0000-7000-8000-0000000000a1');

        return new WaitingCustomer(
            $entry,
            new CustomerMembershipId('0192f3a0-0000-7000-8000-0000000000b1'),
            new ServiceId('0192f3a0-0000-7000-8000-0000000000c1'),
            $collaborator === null ? null : new CollaboratorId($collaborator),
            $date,
            self::at('08:00'),
            [$entry],
            $minutes,
        );
    }

    private static function space(string $from, string $to): ReleasedSpace
    {
        return new ReleasedSpace(new CollaboratorId(self::DANIELA), TimeInterval::between(self::at($from), self::at($to)));
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2030-03-05 {$time}", new DateTimeZone('America/Costa_Rica'));
    }
}
