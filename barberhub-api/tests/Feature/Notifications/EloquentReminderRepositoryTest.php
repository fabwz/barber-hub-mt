<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;
use Src\Notifications\Notices\Infrastructure\Persistence\Repositories\EloquentReminderRepository;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\TestCase;

/**
 * The table of the reminders against PostgreSQL: one row per reservation and kind, moved by a new instant, claimed by a single statement and
 * hidden from the other barbershop by its row policy (NO-03, MT-04).
 */
final class EloquentReminderRepositoryTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private EloquentReminderRepository $reminders;

    private string $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->scopeQueriesTo($this->puraNavaja);
        [, $role] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        $this->reservation = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $role, '2030-03-05T10:00:00-06:00');
        $this->reminders = new EloquentReminderRepository(new FixedBarbershopContext(new BarbershopId($this->puraNavaja)));
    }

    private static function cr(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }

    /** The first call inserts, and repeating it for the same instant changes nothing. */
    public function test_scheduling_twice_for_the_same_instant_is_a_single_row(): void
    {
        $this->assertTrue($this->reminders->schedule($this->reservation, ReminderKind::DayBefore, self::cr('2030-03-04 10:00')));
        $this->assertFalse($this->reminders->schedule($this->reservation, ReminderKind::DayBefore, self::cr('2030-03-04 10:00')));

        $this->assertSame(1, DB::table('reservation_reminders')->count());
    }

    /** Repeating the schedule of a reminder that was already sent does not make it due again (NO-03). */
    public function test_a_sent_reminder_stays_sent_when_the_same_event_arrives_again(): void
    {
        $at = self::cr('2030-03-04 10:00');
        $this->reminders->schedule($this->reservation, ReminderKind::DayBefore, $at);
        $this->assertTrue($this->reminders->claim($this->reservation, ReminderKind::DayBefore, $at, $at));

        $this->assertFalse($this->reminders->schedule($this->reservation, ReminderKind::DayBefore, $at));

        $this->assertNotNull(DB::table('reservation_reminders')->value('sent_at'));
        $this->assertFalse($this->reminders->claim($this->reservation, ReminderKind::DayBefore, $at, $at), 'The second claim loses: it was sent.');
    }

    /** A new instant moves the row and makes it unsent; the claim of the old instant then loses. */
    public function test_a_new_instant_moves_the_row_and_makes_it_unsent(): void
    {
        $old = self::cr('2030-03-04 10:00');
        $new = self::cr('2030-03-11 10:00');
        $this->reminders->schedule($this->reservation, ReminderKind::DayBefore, $old);
        $this->reminders->claim($this->reservation, ReminderKind::DayBefore, $old, $old);

        $this->assertTrue($this->reminders->schedule($this->reservation, ReminderKind::DayBefore, $new));

        $this->assertNull(DB::table('reservation_reminders')->value('sent_at'));
        $this->assertFalse($this->reminders->claim($this->reservation, ReminderKind::DayBefore, $old, $old));
        $this->assertTrue($this->reminders->claim($this->reservation, ReminderKind::DayBefore, $new, $new));
        $this->assertSame(1, DB::table('reservation_reminders')->count());
    }

    /** Forgetting deletes the kind and only that kind. */
    public function test_forgetting_a_kind_leaves_the_other(): void
    {
        $this->reminders->schedule($this->reservation, ReminderKind::DayBefore, self::cr('2030-03-04 10:00'));
        $this->reminders->schedule($this->reservation, ReminderKind::TwoHoursBefore, self::cr('2030-03-05 08:00'));

        $this->reminders->forget($this->reservation, ReminderKind::DayBefore);

        $this->assertSame(['2h'], DB::table('reservation_reminders')->pluck('kind')->all());
    }

    /** The other barbershop sees none of the rows and cannot claim them (MT-04). */
    public function test_another_barbershop_neither_sees_nor_claims_the_rows(): void
    {
        $at = self::cr('2030-03-04 10:00');
        $this->reminders->schedule($this->reservation, ReminderKind::DayBefore, $at);

        $this->scopeQueriesTo($this->fadeLab);
        $other = new EloquentReminderRepository(new FixedBarbershopContext(new BarbershopId($this->fadeLab)));

        $this->assertSame(0, DB::table('reservation_reminders')->count());
        $this->assertFalse($other->claim($this->reservation, ReminderKind::DayBefore, $at, $at));

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertNull(DB::table('reservation_reminders')->value('sent_at'));
    }

    /** The table refuses a kind that is not one of the two (NO-02) and a reservation of another barbershop (MT-04). */
    public function test_the_table_keeps_its_own_rules(): void
    {
        $this->expectException(QueryException::class);

        DB::table('reservation_reminders')->insert(['barbershop_id' => $this->puraNavaja, 'reservation_id' => $this->reservation, 'kind' => '1h', 'scheduled_for' => '2030-03-04 10:00:00-06']);
    }
}
