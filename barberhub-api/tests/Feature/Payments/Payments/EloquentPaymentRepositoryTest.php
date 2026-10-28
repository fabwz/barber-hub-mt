<?php

declare(strict_types=1);

namespace Tests\Feature\Payments\Payments;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\Exceptions\ActivePaymentExistsException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;
use Src\Payments\Payments\Domain\ValueObjects\ProviderAmount;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;
use Src\Payments\Payments\Infrastructure\Persistence\Repositories\EloquentPaymentRepository;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Money;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

final class EloquentPaymentRepositoryTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private string $puraNavaja;

    private string $fadeLab;

    private string $reservation;

    private EloquentPaymentRepository $repository;

    /** Two barbershops and a repository that works on the first one, which has a reservation to pay. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->insertBarbershop('pura-navaja');
        $this->fadeLab = $this->insertBarbershop('fade-lab');
        $this->repository = new EloquentPaymentRepository(new FixedBarbershopContext(new BarbershopId($this->puraNavaja)));
        $this->reservation = $this->insertReservation($this->puraNavaja, '2030-03-04 10:00');
        $this->scopeQueriesTo($this->puraNavaja);
    }

    /** The identifiers are time-ordered UUIDs. */
    public function test_next_id_is_a_version_7_uuid(): void
    {
        $this->assertTrue(Str::isUuid($this->repository->nextId()->value, 7));
    }

    /** A payment is read back as it was saved, and again after its charge and its approval are saved. */
    public function test_a_payment_is_saved_and_changed(): void
    {
        $payment = $this->payment();
        $this->repository->save($payment);

        $found = $this->repository->find($payment->id);
        $this->assertNotNull($found);
        $this->assertSame(PaymentStatus::Pending, $found->status);
        $this->assertSame(PaymentProvider::Onvo, $found->provider);
        $this->assertSame(PaymentMethod::SinpeMovil, $found->method);
        $this->assertSame(2550, $found->amount->colones);
        $this->assertNull($found->providerPaymentId);
        $this->assertEquals($payment->createdAt, $found->createdAt);

        $found->registerCharge('onvo_1', ProviderAmount::of('USD', 510), new DateTimeImmutable('2030-03-01 08:01:00-06:00'));
        $found->approve(new DateTimeImmutable('2030-03-01 08:02:00-06:00'));
        $this->repository->save($found);

        $approved = $this->repository->find($payment->id);
        $this->assertSame(PaymentStatus::Approved, $approved?->status);
        $this->assertSame('onvo_1', $approved->providerPaymentId);
        $this->assertSame('USD', $approved->providerAmount?->currency);
        $this->assertSame(510, $approved->providerAmount->minorUnits);
        $this->assertEquals(new DateTimeImmutable('2030-03-01 08:02:00-06:00'), $approved->updatedAt);
        $this->assertSame(1, DB::table('payments')->count());
    }

    /** The exchange rate of the provider amount and the capture of the approval are saved and read back. */
    public function test_the_exchange_rate_and_the_capture_are_saved(): void
    {
        $payment = $this->payment();
        $this->repository->save($payment);
        $payment->registerCharge('paypal_1', ProviderAmount::of('USD', 510, '500.0000'), new DateTimeImmutable('2030-03-01 08:01:00-06:00'));
        $payment->approve(new DateTimeImmutable('2030-03-01 08:02:00-06:00'), 'CAPTURE-1');
        $this->repository->save($payment);

        $found = $this->repository->find($payment->id);

        $this->assertSame('500.0000', $found?->providerAmount?->exchangeRate);
        $this->assertSame('CAPTURE-1', $found->providerCaptureId);
    }

    /** The mark of an approved payment to give back is saved, and a refund clears it (PA-07). */
    public function test_the_refund_pending_mark_is_saved_and_cleared_by_the_refund(): void
    {
        $payment = $this->payment();
        $this->repository->save($payment);
        $payment->registerCharge('onvo_2', ProviderAmount::of('CRC', 255000), new DateTimeImmutable('2030-03-01 08:01:00-06:00'));
        $payment->approve(new DateTimeImmutable('2030-03-01 08:02:00-06:00'));
        $payment->markRefundPending(new DateTimeImmutable('2030-03-01 08:11:00-06:00'));
        $this->repository->save($payment);

        $marked = $this->repository->find($payment->id);
        $this->assertTrue($marked?->refundPending);

        $marked->refund(new DateTimeImmutable('2030-03-01 08:12:00-06:00'));
        $this->repository->save($marked);

        $this->assertFalse($this->repository->find($payment->id)?->refundPending);
    }

    /** A rejection is saved with its reason. */
    public function test_a_rejection_keeps_its_reason(): void
    {
        $payment = $this->payment();
        $this->repository->save($payment);
        $payment->reject('Card declined', new DateTimeImmutable('2030-03-01 08:03:00-06:00'));
        $this->repository->save($payment);

        $found = $this->repository->find($payment->id);

        $this->assertSame(PaymentStatus::Rejected, $found?->status);
        $this->assertSame('Card declined', $found->failureReason);
    }

    /** A second payment in course for the same reservation is refused as an exception of the domain, which is a 409. */
    public function test_a_second_active_payment_is_refused(): void
    {
        $this->repository->save($this->payment());

        $this->expectException(ActivePaymentExistsException::class);

        $this->repository->save($this->payment());
    }

    /** The active payment of a reservation is the pending or approved one; a rejected one does not count. */
    public function test_the_active_payment_of_a_reservation_is_found(): void
    {
        $this->assertNull($this->repository->findActiveOfReservation(new ReservationId($this->reservation)));

        $rejected = $this->payment();
        $this->repository->save($rejected);
        $rejected->reject('x', new DateTimeImmutable('2030-03-01 08:03:00-06:00'));
        $this->repository->save($rejected);
        $this->assertNull($this->repository->findActiveOfReservation(new ReservationId($this->reservation)));

        $pending = $this->payment();
        $this->repository->save($pending);

        $this->assertSame($pending->id->value, $this->repository->findActiveOfReservation(new ReservationId($this->reservation))?->id->value);
    }

    /** A payment of another barbershop does not exist for this one. */
    public function test_payments_of_other_barbershops_are_not_found(): void
    {
        $payment = $this->payment();
        $this->repository->save($payment);
        $other = new EloquentPaymentRepository(new FixedBarbershopContext(new BarbershopId($this->fadeLab)));
        $this->scopeQueriesTo($this->fadeLab);

        $this->assertNull($other->find($payment->id));
        $this->assertNull($other->findActiveOfReservation(new ReservationId($this->reservation)));
    }

    private function payment(): Payment
    {
        return Payment::create(
            $this->repository->nextId(),
            new BarbershopId($this->puraNavaja),
            new ReservationId($this->reservation),
            PaymentProvider::Onvo,
            PaymentMethod::SinpeMovil,
            Money::of(2550),
            new DateTimeImmutable('2030-03-01 08:00:00-06:00'),
        );
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function insertBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Insert a reservation of a barbershop, with the collaborator, the service and the customer it needs, and return its identifier. */
    private function insertReservation(string $barbershopId, string $startsAt): string
    {
        $this->scopeQueriesTo($barbershopId);
        $collaboratorUser = (string) Str::uuid7();
        $customerUser = (string) Str::uuid7();
        $collaboratorId = (string) Str::uuid7();
        $membershipId = (string) Str::uuid7();
        $serviceId = (string) Str::uuid7();
        $id = (string) Str::uuid7();
        DB::table('users')->insert([
            ['id' => $collaboratorUser, 'name' => 'Kevin', 'email' => "{$collaboratorUser}@barberhub.test", 'password' => 'hash'],
            ['id' => $customerUser, 'name' => 'Ana', 'email' => "{$customerUser}@barberhub.test", 'password' => 'hash'],
        ]);
        DB::table('collaborators')->insert(['id' => $collaboratorId, 'barbershop_id' => $barbershopId, 'user_id' => $collaboratorUser]);
        DB::table('memberships')->insert(['id' => $membershipId, 'user_id' => $customerUser, 'barbershop_id' => $barbershopId, 'role' => 'customer']);
        DB::table('services')->insert(['id' => $serviceId, 'barbershop_id' => $barbershopId, 'name' => 'Degradado', 'duration_minutes' => 45, 'price' => 8500]);
        DB::table('reservations')->insert([
            'id' => $id,
            'barbershop_id' => $barbershopId,
            'collaborator_id' => $collaboratorId,
            'service_id' => $serviceId,
            'customer_membership_id' => $membershipId,
            'status' => 'temporary',
            'starts_at' => "{$startsAt}:00-06",
            'ends_at' => date('Y-m-d H:i:s', (int) strtotime("{$startsAt} +45 minutes")).'-06',
            'blocked_until' => date('Y-m-d H:i:s', (int) strtotime("{$startsAt} +50 minutes")).'-06',
            'price' => 8500,
            'duration_minutes' => 45,
            'cancellation_deadline_hours' => 4,
        ]);

        return $id;
    }
}
