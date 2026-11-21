<?php

declare(strict_types=1);

namespace Tests\Support\Vouchers;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\InsufficientBalanceException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\CustomerMembershipId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\PurchasedVoucherId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\ReservationId;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;

/**
 * Wraps the real repository but takes the session the wrong way: it reads the balance, decides, waits a moment and writes the
 * balance it read minus one, with no conditional update and no lock on the row. It shows in a test what the atomic and conditional
 * update of the real repository is for (BO-03): without it the same session is sold more than once. The wait only widens the
 * window between the read and the write so that the race does not depend on the speed of the machine.
 */
final class UncontrolledVoucherRepository implements PurchasedVoucherRepository
{
    /** Keep the repository that does the rest of the work, the port that tells the barbershop and how long the read stays stale. */
    public function __construct(
        private readonly PurchasedVoucherRepository $inner,
        private readonly BarbershopContext $context,
        private readonly int $windowMicroseconds = 300_000,
    ) {}

    /** Delegate. */
    public function nextId(): PurchasedVoucherId
    {
        return $this->inner->nextId();
    }

    /** Delegate. */
    public function save(PurchasedVoucher $voucher): void
    {
        $this->inner->save($voucher);
    }

    /** Delegate. */
    public function find(PurchasedVoucherId $id): ?PurchasedVoucher
    {
        return $this->inner->find($id);
    }

    /** Delegate. */
    public function findForUpdate(PurchasedVoucherId $id): ?PurchasedVoucher
    {
        return $this->inner->findForUpdate($id);
    }

    /** Delegate. */
    public function findPendingOfCustomer(CustomerMembershipId $customer, VoucherDefinitionId $definition): ?PurchasedVoucher
    {
        return $this->inner->findPendingOfCustomer($customer, $definition);
    }

    /** Delegate. */
    public function movementsOf(PurchasedVoucherId $id): array
    {
        return $this->inner->movementsOf($id);
    }

    /** Delegate. */
    public function movementsOfMany(array $ids): array
    {
        return $this->inner->movementsOfMany($ids);
    }

    /** Delegate. */
    public function pageOfCustomer(CustomerMembershipId $customer, PageRequest $page): Page
    {
        return $this->inner->pageOfCustomer($customer, $page);
    }

    /** Delegate. */
    public function pageOfBarbershop(PageRequest $page): Page
    {
        return $this->inner->pageOfBarbershop($page);
    }

    /** Read the balance, wait, and write what was read minus one: the check and the write are two steps, so two requests can both see the last session. */
    public function holdSession(PurchasedVoucherId $id, ReservationId $reservation, DateTimeImmutable $at, ?DateTimeImmutable $now = null): void
    {
        DB::transaction(function () use ($id, $reservation): void {
            $barbershop = $this->context->current()->value;
            $remaining = (int) DB::table('purchased_vouchers')->where('barbershop_id', $barbershop)->where('id', $id->value)->value('remaining_sessions');

            if ($remaining <= 0) {
                throw InsufficientBalanceException::create();
            }

            usleep($this->windowMicroseconds);

            DB::table('purchased_vouchers')->where('barbershop_id', $barbershop)->where('id', $id->value)->update(['remaining_sessions' => $remaining - 1]);
            DB::table('voucher_movements')->insert([
                'id' => (string) Str::uuid7(),
                'barbershop_id' => $barbershop,
                'purchased_voucher_id' => $id->value,
                'reservation_id' => $reservation->value,
                'type' => 'hold',
                'sessions_delta' => -1,
            ]);
        });
    }

    /** Delegate. */
    public function activeOfCustomer(CustomerMembershipId $customer): array
    {
        return $this->inner->activeOfCustomer($customer);
    }
}
