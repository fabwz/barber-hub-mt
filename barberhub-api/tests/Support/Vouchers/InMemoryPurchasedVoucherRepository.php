<?php

declare(strict_types=1);

namespace Tests\Support\Vouchers;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\VoucherMovement;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotFoundException;
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

    /**
     * The record of each voucher of the list.
     *
     * @param  list<PurchasedVoucherId>  $ids
     * @return array<string, list<VoucherMovement>>
     */
    public function movementsOfMany(array $ids): array
    {
        $record = [];

        foreach ($ids as $id) {
            $record[$id->value] = $this->movementsOf($id);
        }

        return $record;
    }

    /**
     * One page of the paid vouchers of a customer, the latest activated first.
     *
     * @return Page<PurchasedVoucher>
     */
    public function pageOfCustomer(CustomerMembershipId $customer, PageRequest $page): Page
    {
        return $this->page(fn (PurchasedVoucher $voucher): bool => $voucher->customerMembershipId->equals($customer), $page);
    }

    /**
     * One page of the paid vouchers of the barbershop, the latest activated first.
     *
     * @return Page<PurchasedVoucher>
     */
    public function pageOfBarbershop(PageRequest $page): Page
    {
        return $this->page(fn (PurchasedVoucher $voucher): bool => true, $page);
    }

    /** Set a session aside with the rules of the aggregate, which decide the same as the conditional update of the database. */
    public function holdSession(PurchasedVoucherId $id, ReservationId $reservation, DateTimeImmutable $at, ?DateTimeImmutable $now = null): void
    {
        $voucher = $this->find($id) ?? throw VoucherNotFoundException::create();
        $voucher->hold($reservation, $at);
        $this->save($voucher);
    }

    /**
     * The paid vouchers of a customer, the one that expires first leading.
     *
     * @return list<PurchasedVoucher>
     */
    public function activeOfCustomer(CustomerMembershipId $customer): array
    {
        $paid = array_values(array_filter(
            $this->vouchers,
            fn (PurchasedVoucher $voucher): bool => $voucher->status === VoucherStatus::Active && $voucher->customerMembershipId->equals($customer),
        ));
        usort($paid, fn (PurchasedVoucher $a, PurchasedVoucher $b): int => [$a->expiresAt, $a->id->value] <=> [$b->expiresAt, $b->id->value]);

        return $paid;
    }

    /**
     * @param  callable(PurchasedVoucher): bool  $wanted
     * @return Page<PurchasedVoucher>
     */
    private function page(callable $wanted, PageRequest $page): Page
    {
        $paid = array_values(array_filter($this->vouchers, fn (PurchasedVoucher $voucher): bool => $voucher->status === VoucherStatus::Active && $wanted($voucher)));
        usort($paid, fn (PurchasedVoucher $a, PurchasedVoucher $b): int => [$b->activatedAt, $b->id->value] <=> [$a->activatedAt, $a->id->value]);

        return new Page(array_slice($paid, $page->offset(), $page->size), count($paid), $page);
    }

    /** How many vouchers are stored. */
    public function count(): int
    {
        return count($this->vouchers);
    }
}
