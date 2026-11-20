<?php

declare(strict_types=1);

namespace Tests\Unit\Availability\Slots\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Availability\Slots\Application\Contracts\BlockedIntervals;
use Src\Availability\Slots\Application\UseCases\IsSpaceFree;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Tests\Support\Availability\InMemoryBlockedIntervals;

/** Whether a whole space is free of what reservations and offers hold, without a database: the question the line asks before it offers a space (DI-05). */
final class IsSpaceFreeTest extends TestCase
{
    private const string DANIELA = '0192f3a0-0000-7000-8000-000000000001';

    private const string KEVIN = '0192f3a0-0000-7000-8000-000000000002';

    /** A space with nothing held in it is free. */
    public function test_a_space_with_nothing_held_is_free(): void
    {
        $this->assertTrue(new IsSpaceFree(new InMemoryBlockedIntervals)->execute(self::DANIELA, self::space('10:00', '10:50'), self::now()));
    }

    /** A space with something held in it, a reservation or an offer, is not free. */
    public function test_a_space_with_something_held_is_not_free(): void
    {
        $blocked = new InMemoryBlockedIntervals([self::DANIELA => [self::space('10:30', '11:20')]]);

        $this->assertFalse(new IsSpaceFree($blocked)->execute(self::DANIELA, self::space('10:00', '10:50'), self::now()));
    }

    /** What another collaborator holds does not count (DI-04). */
    public function test_what_another_collaborator_holds_does_not_count(): void
    {
        $blocked = new InMemoryBlockedIntervals([self::KEVIN => [self::space('10:00', '10:50')]]);

        $this->assertTrue(new IsSpaceFree($blocked)->execute(self::DANIELA, self::space('10:00', '10:50'), self::now()));
    }

    /** It asks only for the collaborator and the window of the space, with the current instant, and reads once. */
    public function test_it_asks_for_the_collaborator_and_the_window_of_the_space(): void
    {
        $asked = [];
        $blocked = new class($asked) implements BlockedIntervals
        {
            /** @param  list<array{list<string>, TimeInterval, DateTimeImmutable, string|null}>  $asked */
            public function __construct(public array &$asked) {}

            public function between(array $collaboratorIds, TimeInterval $window, DateTimeImmutable $now, ?string $ignoringReservationId = null): array
            {
                $this->asked[] = [$collaboratorIds, $window, $now, $ignoringReservationId];

                return [];
            }
        };
        $space = self::space('10:00', '10:50');

        new IsSpaceFree($blocked)->execute(self::DANIELA, $space, self::now());

        $this->assertCount(1, $asked);
        $this->assertSame([self::DANIELA], $asked[0][0]);
        $this->assertSame($space, $asked[0][1]);
        $this->assertEquals(self::now(), $asked[0][2]);
        $this->assertNull($asked[0][3], 'No reservation is being moved.');
    }

    private static function space(string $from, string $to): TimeInterval
    {
        return TimeInterval::between(self::at($from), self::at($to));
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica'));
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2030-03-05 {$time}", new DateTimeZone('America/Costa_Rica'));
    }
}
