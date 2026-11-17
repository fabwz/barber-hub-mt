<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\UseCases;

use DateTimeImmutable;
use Src\Vouchers\PurchasedVouchers\Application\SettledSessions;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotFoundException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\PurchasedVoucherId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\ReservationId;

final readonly class ReleaseVoucherSession
{
    /** Receive the voucher repository. */
    public function __construct(private PurchasedVoucherRepository $vouchers) {}

    /**
     * Give the session of a reservation back to its voucher: the customer cancelled in time or the barbershop cancelled (BO-05,
     * CA-02). The voucher row is locked, so two simultaneous settlements run in turn, and a reservation whose session was already
     * settled, by a release or a consumption, changes nothing (CA-06); the unique index of the record is the second barrier. It
     * must run inside the transaction of the cancellation.
     *
     * @throws VoucherNotFoundException
     */
    public function execute(string $voucherId, string $reservationId, DateTimeImmutable $now): void
    {
        $id = new PurchasedVoucherId($voucherId);
        $voucher = $this->vouchers->findForUpdate($id) ?? throw VoucherNotFoundException::create();

        if (SettledSessions::in($this->vouchers->movementsOf($id), $reservationId)) {
            return;
        }

        $voucher->release(new ReservationId($reservationId), $now);
        $this->vouchers->save($voucher);
    }
}
