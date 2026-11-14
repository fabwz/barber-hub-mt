<?php

declare(strict_types=1);

namespace Tests\Support\Vouchers;

use DateTimeImmutable;
use LogicException;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\VoucherMovement;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\CustomerMembershipId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\PurchasedVoucherId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\ReservationId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\VoucherStatus;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;

/** The purchased vouchers in memory, for the tests of the use cases: no database and no concurrency. */
final class InMemoryPurchasedVoucherRepository implements PurchasedVoucherRepository
{
    /** @var array<string, PurchasedVoucher> */
    private array $vouchers = [];

    /** @var array<string, list<VoucherMovement>> */
    private array $record = [];

    private int $sequence = 0;

    /** Generate a predictable identity. */
    public function nextId(): PurchasedVoucherId
    {
        return new PurchasedVoucherId(sprintf('0192f0c4-1111-7000-8000-%012d', ++$this->sequence));
    }

    /** Store the voucher and append its new entries to the record, as the real repository does. */
    public function save(PurchasedVoucher $voucher): void
    {
        $this->vouchers[$voucher->id->value] = $voucher;
        $this->record[$voucher->id->value] = [...($this->record[$voucher->id->value] ?? []), ...$voucher->pullMovements()];
    }

    /** Find a voucher by its identity. */
    public function find(PurchasedVoucherId $id): ?PurchasedVoucher
    {
        return $this->vouchers[$id->value] ?? null;
    }

    /** Find a voucher by its identity; there is nothing to lock in memory. */
    public function findForUpdate(PurchasedVoucherId $id): ?PurchasedVoucher
    {
        return $this->find($id);
    }

    /** Find the unpaid voucher of the customer for the definition. */
    public function findPendingOfCustomer(CustomerMembershipId $customer, VoucherDefinitionId $definition): ?PurchasedVoucher
    {
        return array_find(
            $this->vouchers,
            fn (PurchasedVoucher $voucher): bool => $voucher->status === VoucherStatus::Pending
                && $voucher->customerMembershipId->equals($customer)
                && $voucher->voucherDefinitionId->equals($definition),
        );
    }

    /**
     * The record of a voucher.
     *
     * @return list<VoucherMovement>
     */
    public function movementsOf(PurchasedVoucherId $id): array
    {
        return $this->record[$id->value] ?? [];
    }

    /** Not used by the tests of the purchase. */
    public function holdSession(PurchasedVoucherId $id, ReservationId $reservation, DateTimeImmutable $at): void
    {
        throw new LogicException('The tests of the purchase never hold a session.');
    }

    /** How many vouchers are stored. */
    public function count(): int
    {
        return count($this->vouchers);
    }
}
