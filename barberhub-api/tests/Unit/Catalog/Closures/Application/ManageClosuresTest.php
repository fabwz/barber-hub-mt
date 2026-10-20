<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Closures\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Catalog\Closures\Application\DTOs\RegisterClosureCommand;
use Src\Catalog\Closures\Application\UseCases\DeleteClosure;
use Src\Catalog\Closures\Application\UseCases\ListClosures;
use Src\Catalog\Closures\Application\UseCases\RegisterClosure;
use Src\Catalog\Closures\Domain\Entities\Closure;
use Src\Catalog\Closures\Domain\Exceptions\ClosureAlreadyExistsException;
use Src\Catalog\Closures\Domain\Exceptions\ClosureNotFoundException;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureDate;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Tests\Support\Catalog\InMemoryClosureRepository;

/** The owner marks holidays and special closures by date (RF-04, HO-01). */
final class ManageClosuresTest extends TestCase
{
    private InMemoryClosureRepository $closures;

    private RegisterClosure $registerClosure;

    private DateTimeImmutable $now;

    /** Wire the use cases to the in-memory repository; "now" is 20 October 2026 at 10:00 in Costa Rica. */
    protected function setUp(): void
    {
        $this->closures = new InMemoryClosureRepository;
        $this->registerClosure = new RegisterClosure($this->closures);
        $this->now = new DateTimeImmutable('2026-10-20 10:00:00', new DateTimeZone('America/Costa_Rica'));
    }

    /** A closure keeps its date and its reason. */
    public function test_closure_is_registered(): void
    {
        $closure = $this->registerClosure->execute(new RegisterClosureCommand('2026-12-25', '  Navidad '), $this->now);

        $this->assertSame('2026-12-25', $closure->date->value);
        $this->assertSame('Navidad', $closure->reason?->value);
        $this->assertEquals($closure, $this->closures->find($closure->id));
    }

    /** The reason is optional. */
    public function test_reason_is_optional(): void
    {
        $this->assertNull($this->registerClosure->execute(new RegisterClosureCommand('2026-12-25', null), $this->now)->reason);
    }

    /** Today can be closed, a past date cannot. */
    public function test_today_is_accepted_and_the_past_is_refused(): void
    {
        $this->registerClosure->execute(new RegisterClosureCommand('2026-10-20', null), $this->now);

        try {
            $this->registerClosure->execute(new RegisterClosureCommand('2026-10-19', null), $this->now);
            $this->fail('A past date was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame('closures.date_in_past', $exception->translationKey());
        }

        $this->assertCount(1, $this->closures->from(new ClosureDate('2026-01-01')));
    }

    /** A date that does not exist or an oversized reason are refused. */
    public function test_invalid_date_and_reason_are_refused(): void
    {
        foreach ([['2026-02-30', null, 'closures.date_invalid'], ['25/12/2026', null, 'closures.date_invalid'], ['2026-12-25', '   ', 'closures.reason_invalid'], ['2026-12-25', str_repeat('a', 201), 'closures.reason_invalid']] as [$date, $reason, $key]) {
            try {
                $this->registerClosure->execute(new RegisterClosureCommand($date, $reason), $this->now);
                $this->fail("The closure {$date} was accepted.");
            } catch (InvalidValueException $exception) {
                $this->assertSame($key, $exception->translationKey());
            }
        }
    }

    /** A date is closed once (HO-01): a second closure on it is refused. */
    public function test_a_date_cannot_be_closed_twice(): void
    {
        $this->registerClosure->execute(new RegisterClosureCommand('2026-12-25', null), $this->now);

        try {
            $this->registerClosure->execute(new RegisterClosureCommand('2026-12-25', 'Otra vez'), $this->now);
            $this->fail('A second closure on the same date was accepted.');
        } catch (ClosureAlreadyExistsException $exception) {
            $this->assertSame('closures.already_exists', $exception->translationKey());
        }
    }

    /** The list starts today and goes by date. */
    public function test_closures_are_listed_from_today_by_date(): void
    {
        $this->registerClosure->execute(new RegisterClosureCommand('2026-12-25', null), $this->now);
        $this->registerClosure->execute(new RegisterClosureCommand('2026-10-20', null), $this->now);
        $this->registerClosure->execute(new RegisterClosureCommand('2026-11-02', null), $this->now);

        $dates = array_map(fn (Closure $closure): string => $closure->date->value, new ListClosures($this->closures)->execute($this->now));

        $this->assertSame(['2026-10-20', '2026-11-02', '2026-12-25'], $dates);
    }

    /** A date that passed no longer shows in the list, even though it is stored. */
    public function test_past_closures_are_not_listed(): void
    {
        $this->registerClosure->execute(new RegisterClosureCommand('2026-10-20', null), $this->now);
        $tomorrow = $this->now->modify('+1 day');

        $this->assertSame([], new ListClosures($this->closures)->execute($tomorrow));
    }

    /** A deleted closure opens the date again, and deleting an unknown one is a not-found error. */
    public function test_closure_is_deleted(): void
    {
        $closure = $this->registerClosure->execute(new RegisterClosureCommand('2026-12-25', null), $this->now);
        $delete = new DeleteClosure($this->closures);

        $delete->execute($closure->id->value);

        $this->assertNull($this->closures->find($closure->id));
        $this->registerClosure->execute(new RegisterClosureCommand('2026-12-25', null), $this->now);

        $this->expectException(ClosureNotFoundException::class);
        $delete->execute($closure->id->value);
    }
}
