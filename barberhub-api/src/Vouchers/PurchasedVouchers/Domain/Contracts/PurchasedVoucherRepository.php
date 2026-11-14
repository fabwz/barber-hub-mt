<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Domain\Contracts;

use DateTimeImmutable;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\VoucherMovement;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\InsufficientBalanceException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherExpiredException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotActiveException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotFoundException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\PurchasedVoucherId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\ReservationId;

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

    /**
     * The record of the balance of a voucher of the current barbershop, in the order it was written.
     *
     * @return list<VoucherMovement>
     */
    public function movementsOf(PurchasedVoucherId $id): array;

    /**
     * Set a session aside for a reservation, atomically: the balance goes down by one only if the voucher is active, valid at
     * the given instant and has a session left, in a single conditional update, and the entry joins the record in the same
     * transaction. Two simultaneous calls for the last session cannot both win, so the balance never goes below zero (BO-03).
     * It is the counterpart in the database of PurchasedVoucher::hold(), which decides the same in memory.
     *
     * @throws VoucherNotFoundException when the voucher is not one of the current barbershop
     * @throws VoucherNotActiveException when its payment is not confirmed
     * @throws VoucherExpiredException when it is not valid at the instant
     * @throws InsufficientBalanceException when no session is left
     */
    public function holdSession(PurchasedVoucherId $id, ReservationId $reservation, DateTimeImmutable $at): void;
}
