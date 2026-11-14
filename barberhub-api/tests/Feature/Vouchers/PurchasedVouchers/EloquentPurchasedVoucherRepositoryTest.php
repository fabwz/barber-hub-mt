<?php

declare(strict_types=1);

namespace Tests\Feature\Vouchers\PurchasedVouchers;

use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\VoucherMovement;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\InsufficientBalanceException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherExpiredException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotActiveException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotFoundException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\CustomerMembershipId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\PurchasedVoucherId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\ReservationId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\VoucherMovementType;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\VoucherStatus;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Vouchers\SeedsVouchers;
use Tests\Support\Vouchers\VoucherFactory;
use Tests\TestCase;

/** The adapter of the purchased vouchers: what it stores, how it keeps the record and how it takes a session without going below zero (BO-03, BO-09, MT-03). */
final class EloquentPurchasedVoucherRepositoryTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;
    use SeedsVouchers;

    private PurchasedVoucherRepository $vouchers;

    private string $membership;

    private string $definition;

    private int $reservations = 0;

    /** Pura Navaja with a customer and a voucher definition; the repository works as that barbershop. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        [, $this->membership] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        $this->definition = $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado]);
        $this->workAs($this->puraNavaja);
    }

    /** A purchase saved, then activated and saved again, comes back as it was written, with the purchase in the record (BO-02). */
    public function test_a_voucher_is_saved_activated_and_read_back(): void
    {
        $id = $this->vouchers->nextId();
        $voucher = PurchasedVoucher::purchase(
            $id, new BarbershopId($this->puraNavaja), new CustomerMembershipId($this->membership), new VoucherDefinitionId($this->definition),
            VoucherFactory::pending()->name, VoucherFactory::pending()->price, VoucherFactory::pending()->sessionsCount, VoucherFactory::pending()->validityDays, VoucherFactory::now(),
        );
        $this->vouchers->save($voucher);

        $pending = $this->vouchers->find($id);
        $this->assertNotNull($pending);
        $this->assertSame([VoucherStatus::Pending, 0, null], [$pending->status, $pending->balance->value, $pending->activatedAt]);
        $this->assertSame([], $this->vouchers->movementsOf($id));

        $paidAt = new DateTimeImmutable('2030-03-02 10:30:00-06:00');
        $pending->activate($paidAt);
        $this->vouchers->save($pending);

        $active = $this->vouchers->find($id);
        $this->assertNotNull($active);
        $this->assertSame([VoucherStatus::Active, 5, 'Bono 5 cortes', 30000, 5, 90], [$active->status, $active->balance->value, $active->name->value, $active->price->colones, $active->sessionsCount->value, $active->validityDays->value]);
        $this->assertEquals($paidAt, $active->activatedAt);
        $this->assertEquals(new DateTimeImmutable('2030-05-31 10:30:00-06:00'), $active->expiresAt);
        $this->assertSame($this->membership, $active->customerMembershipId->value);
        $this->assertSame($this->definition, $active->voucherDefinitionId->value);
        $this->assertSame([VoucherMovementType::Purchase], array_map(fn (VoucherMovement $movement): VoucherMovementType => $movement->type, $this->vouchers->movementsOf($id)));
    }

    /** Saving the same aggregate twice writes its entries once. */
    public function test_saving_twice_writes_the_entries_once(): void
    {
        $voucher = $this->persistedActiveVoucher();

        $this->vouchers->save($voucher);

        $this->assertCount(1, $this->vouchers->movementsOf($voucher->id));
    }

    /** The five sessions go one by one, the sixth is refused, and the balance and the record end in agreement at zero (BO-03, BO-09). */
    public function test_the_sessions_are_taken_one_by_one_and_the_last_refusal_leaves_the_balance_at_zero(): void
    {
        $voucher = $this->persistedActiveVoucher();

        for ($n = 1; $n <= 5; $n++) {
            $this->vouchers->holdSession($voucher->id, $this->reservation(), VoucherFactory::now());
            $this->assertSame(5 - $n, $this->storedBalance($voucher->id));
        }

        try {
            $this->vouchers->holdSession($voucher->id, $this->reservation(), VoucherFactory::now());
            $this->fail('The sixth session does not exist.');
        } catch (InsufficientBalanceException $exception) {
            $this->assertSame('saldo-insuficiente', $exception->rule());
        }

        $this->assertSame(0, $this->storedBalance($voucher->id));
        $this->assertSame(0, VoucherMovement::balanceOf($this->vouchers->movementsOf($voucher->id)));
        $this->assertCount(6, $this->vouchers->movementsOf($voucher->id));
    }

    /** A refused session leaves nothing behind: no entry, no change of the date of the voucher. */
    public function test_a_refused_session_leaves_no_entry(): void
    {
        $voucher = $this->persistedActiveVoucher(sessions: 1);
        $this->vouchers->holdSession($voucher->id, $this->reservation(), VoucherFactory::now());
        $before = DB::table('purchased_vouchers')->where('id', $voucher->id->value)->value('updated_at');

        try {
            $this->vouchers->holdSession($voucher->id, $this->reservation(), VoucherFactory::now()->modify('+1 minute'));
            $this->fail('No session was left.');
        } catch (InsufficientBalanceException) {
            $this->assertCount(2, $this->vouchers->movementsOf($voucher->id));
            $this->assertSame($before, DB::table('purchased_vouchers')->where('id', $voucher->id->value)->value('updated_at'));
        }
    }

    /** The refusals tell why: not there, not paid, expired (at the instant of the expiry, not before) or without sessions (BO-02, BO-06, BO-07). */
    public function test_a_refusal_tells_why(): void
    {
        $active = $this->persistedActiveVoucher();
        $pending = VoucherFactory::pending(id: (string) Str::uuid7(), barbershop: $this->puraNavaja, membership: $this->membership, definition: $this->definition);
        $this->vouchers->save($pending);
        $expiresAt = $active->expiresAt;
        $this->assertNotNull($expiresAt);

        $this->assertRefused(VoucherNotFoundException::class, new PurchasedVoucherId((string) Str::uuid7()), VoucherFactory::now());
        $this->assertRefused(VoucherNotActiveException::class, $pending->id, VoucherFactory::now());
        $this->assertRefused(VoucherExpiredException::class, $active->id, $expiresAt);
        $this->vouchers->holdSession($active->id, $this->reservation(), $expiresAt->modify('-1 second'));
        $this->assertSame(4, $this->storedBalance($active->id));
    }

    /** The database and the aggregate decide the same: whatever the aggregate refuses in memory, the repository refuses with the same error. */
    public function test_the_repository_and_the_aggregate_refuse_the_same_cases(): void
    {
        $voucher = $this->persistedActiveVoucher(sessions: 1);
        $expiresAt = $voucher->expiresAt;
        $this->assertNotNull($expiresAt);
        $cases = [
            'valid' => [VoucherFactory::now(), null],
            'expired' => [$expiresAt, VoucherExpiredException::class],
            'no session left' => [VoucherFactory::now(), InsufficientBalanceException::class],
            'expired and empty' => [$expiresAt->modify('+1 day'), VoucherExpiredException::class],
        ];

        foreach ($cases as $name => [$at, $expected]) {
            $inMemory = $this->vouchers->find($voucher->id);
            $this->assertNotNull($inMemory);
            $reservation = $this->reservation();
            $fromAggregate = $this->outcome(fn () => $inMemory->hold($reservation, $at));
            $fromRepository = $this->outcome(fn () => $this->vouchers->holdSession($voucher->id, $reservation, $at));

            $this->assertSame($expected, $fromAggregate, "{$name}: the aggregate");
            $this->assertSame($expected, $fromRepository, "{$name}: the repository");
        }
    }

    /** Setting aside the same reservation twice is refused by the record and the balance does not move (BO-04). */
    public function test_the_same_reservation_cannot_hold_twice(): void
    {
        $voucher = $this->persistedActiveVoucher();
        $reservation = $this->reservation();
        $this->vouchers->holdSession($voucher->id, $reservation, VoucherFactory::now());

        try {
            $this->vouchers->holdSession($voucher->id, $reservation, VoucherFactory::now());
            $this->fail('A reservation sets one session aside.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', (string) $exception->getCode());
        }

        $this->assertSame(4, $this->storedBalance($voucher->id));
    }

    /** A reservation that is cancelled in time gives its session back through the aggregate, read with its row locked (BO-05, BO-09). */
    public function test_a_session_given_back_through_the_aggregate_keeps_balance_and_record_in_agreement(): void
    {
        $voucher = $this->persistedActiveVoucher();
        $reservation = $this->reservation();
        $this->vouchers->holdSession($voucher->id, $reservation, VoucherFactory::now());

        DB::transaction(function () use ($voucher, $reservation): void {
            $locked = $this->vouchers->findForUpdate($voucher->id);
            $this->assertNotNull($locked);
            $locked->release($reservation, VoucherFactory::now()->modify('+1 hour'));
            $this->vouchers->save($locked);
        });

        $this->assertSame(5, $this->storedBalance($voucher->id));
        $this->assertSame(5, VoucherMovement::balanceOf($this->vouchers->movementsOf($voucher->id)));
        $this->assertSame(
            [VoucherMovementType::Purchase, VoucherMovementType::Hold, VoucherMovementType::Release],
            array_map(fn (VoucherMovement $movement): VoucherMovementType => $movement->type, $this->vouchers->movementsOf($voucher->id)),
        );
    }

    /** The repository never reaches a voucher of another barbershop: not to read it, not to lock it, not to take its sessions (MT-03). */
    public function test_a_voucher_of_another_barbershop_is_out_of_reach(): void
    {
        $voucher = $this->persistedActiveVoucher();
        $this->workAs($this->fadeLab);

        $this->assertNull($this->vouchers->find($voucher->id));
        $this->assertNull($this->vouchers->findForUpdate($voucher->id));
        $this->assertSame([], $this->vouchers->movementsOf($voucher->id));
        $this->assertRefused(VoucherNotFoundException::class, $voucher->id, VoucherFactory::now());

        $this->workAs($this->puraNavaja);
        $this->assertSame(5, $this->storedBalance($voucher->id));
    }

    /** An active voucher of Pura Navaja, saved with its purchase in the record. */
    private function persistedActiveVoucher(int $sessions = 5): PurchasedVoucher
    {
        $voucher = VoucherFactory::active(sessions: $sessions, id: (string) Str::uuid7(), barbershop: $this->puraNavaja, membership: $this->membership, definition: $this->definition);
        $this->vouchers->save($voucher);

        return $voucher;
    }

    /** A new confirmed reservation of the customer, an hour after the previous one, because a customer has one at a time (RE-06). */
    private function reservation(): ReservationId
    {
        $start = (new DateTimeImmutable('2030-03-05 08:00:00-06:00'))->modify('+'.$this->reservations++.' hours');

        return new ReservationId($this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->membership, $start->format('Y-m-d H:i:sP')));
    }

    private function storedBalance(PurchasedVoucherId $id): int
    {
        return (int) DB::table('purchased_vouchers')->where('id', $id->value)->value('remaining_sessions');
    }

    /**
     * @param  class-string  $exception
     */
    private function assertRefused(string $exception, PurchasedVoucherId $id, DateTimeImmutable $at): void
    {
        $this->assertSame($exception, $this->outcome(fn () => $this->vouchers->holdSession($id, $this->reservation(), $at)));
    }

    /** The class of the domain exception an operation throws, or null when it works. */
    private function outcome(callable $operation): ?string
    {
        try {
            $operation();

            return null;
        } catch (VoucherNotFoundException|VoucherNotActiveException|VoucherExpiredException|InsufficientBalanceException $exception) {
            return $exception::class;
        }
    }

    /** Make the repository, and the queries of the test, work as a barbershop. */
    private function workAs(string $barbershopId): void
    {
        $this->app->instance(BarbershopContext::class, new FixedBarbershopContext(new BarbershopId($barbershopId)));
        $this->scopeQueriesTo($barbershopId);
        $this->vouchers = $this->app->make(PurchasedVoucherRepository::class);
    }
}
