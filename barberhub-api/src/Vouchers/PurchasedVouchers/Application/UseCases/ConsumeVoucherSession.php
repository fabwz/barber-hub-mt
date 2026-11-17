<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\UseCases;

use DateTimeImmutable;
use Src\Vouchers\PurchasedVouchers\Application\SettledSessions;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotFoundException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\PurchasedVoucherId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\ReservationId;

final readonly class ConsumeVoucherSession
{
    /** Receive the voucher repository. */
    public function __construct(private PurchasedVoucherRepository $vouchers) {}

    /**
     * Leave the session of a reservation used: the customer cancelled late or did not show up (BO-05, CA-03). The session was
     * taken when the reservation was confirmed (BO-04), so the balance does not change and the entry says how it ended. Like the
     * release, it locks the voucher and does nothing for a reservation whose session was already settled (CA-06). It must run
     * inside the transaction that changes the reservation.
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

        $voucher->consume(new ReservationId($reservationId), $now);
        $this->vouchers->save($voucher);
    }
}
