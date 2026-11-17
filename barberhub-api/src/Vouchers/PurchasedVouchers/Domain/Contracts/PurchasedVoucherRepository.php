<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Domain\Contracts;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\VoucherMovement;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\InsufficientBalanceException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherExpiredException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotActiveException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotFoundException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\CustomerMembershipId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\PurchasedVoucherId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\ReservationId;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;

interface PurchasedVoucherRepository
{
    /** Generate the identity for a new purchased voucher. */
    public function nextId(): PurchasedVoucherId;

    /**
     * Persist a new voucher, or the changes of an existing one, and append the entries it recorded to the record of the balance.
     * It writes the balance the aggregate holds, so a voucher that is changed must be read with findForUpdate first.
     */
    public function save(PurchasedVoucher $voucher): void;

    /** Find a voucher among those of the current barbershop. */
    public function find(PurchasedVoucherId $id): ?PurchasedVoucher;

    /** Find a voucher among those of the current barbershop and lock its row until the transaction ends, so two changes of it run in turn. */
    public function findForUpdate(PurchasedVoucherId $id): ?PurchasedVoucher;

    /** Find the voucher of a customer of the current barbershop that is bought from the definition and still waits for its payment, if there is one. */
    public function findPendingOfCustomer(CustomerMembershipId $customer, VoucherDefinitionId $definition): ?PurchasedVoucher;

    /**
     * The record of the balance of a voucher of the current barbershop, in the order it was written.
     *
     * @return list<VoucherMovement>
     */
    public function movementsOf(PurchasedVoucherId $id): array;

    /**
     * The record of each voucher of the list, in the order it was written, indexed by the identifier of the voucher; a voucher without
     * entries has an empty list. One read for all of them, so a page of vouchers does not read the record once per voucher.
     *
     * @param  list<PurchasedVoucherId>  $ids
     * @return array<string, list<VoucherMovement>>
     */
    public function movementsOfMany(array $ids): array;

    /**
     * One page of the paid vouchers a customer bought in the current barbershop, the latest activated first. A voucher that
     * still waits for its payment is not listed: it is not a voucher yet (BO-02).
     *
     * @return Page<PurchasedVoucher>
     */
    public function pageOfCustomer(CustomerMembershipId $customer, PageRequest $page): Page;

    /**
     * One page of the paid vouchers sold in the current barbershop to any customer, the latest activated first. A voucher that
     * still waits for its payment is not listed (BO-02).
     *
     * @return Page<PurchasedVoucher>
     */
    public function pageOfBarbershop(PageRequest $page): Page;

    /**
     * Set a session aside for a reservation, atomically: the balance goes down by one only if the voucher is active, valid at
     * the given instant and has a session left, in a single conditional update, and the entry joins the record in the same
     * transaction. Two simultaneous calls for the last session cannot both win, so the balance never goes below zero (BO-03).
     * It is the counterpart in the database of PurchasedVoucher::hold(), which decides the same in memory. The validity is
     * checked at `$at`, the instant the voucher must cover (the appointment of the reservation, BO-06), while the entry of the record
     * and the update of the row are stamped with `$now`, the instant it happens; without `$now` both are `$at`.
     *
     * @throws VoucherNotFoundException when the voucher is not one of the current barbershop
     * @throws VoucherNotActiveException when its payment is not confirmed
     * @throws VoucherExpiredException when it is not valid at the instant
     * @throws InsufficientBalanceException when no session is left
     */
    public function holdSession(PurchasedVoucherId $id, ReservationId $reservation, DateTimeImmutable $at, ?DateTimeImmutable $now = null): void;

    /**
     * The paid vouchers of a customer in the current barbershop, expired or not, with or without sessions left, the one that expires
     * first leading and the identifier breaking the tie (BO-08). Those that wait for their payment are left out (BO-02).
     *
     * @return list<PurchasedVoucher>
     */
    public function activeOfCustomer(CustomerMembershipId $customer): array;
}
