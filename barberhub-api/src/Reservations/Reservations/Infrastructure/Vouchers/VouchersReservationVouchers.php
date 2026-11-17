<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Vouchers;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\Contracts\ReservationVouchers;
use Src\Vouchers\PurchasedVouchers\Application\UseCases\ConsumeVoucherSession;
use Src\Vouchers\PurchasedVouchers\Application\UseCases\HoldVoucherSession;
use Src\Vouchers\PurchasedVouchers\Application\UseCases\ReleaseVoucherSession;

final readonly class VouchersReservationVouchers implements ReservationVouchers
{
    /** Receive the PurchasedVouchers use cases that set a session aside, give it back and use it. */
    public function __construct(
        private HoldVoucherSession $hold,
        private ReleaseVoucherSession $release,
        private ConsumeVoucherSession $consume,
    ) {}

    /** Set a session aside through the Vouchers module. */
    public function hold(string $customerMembershipId, string $serviceId, string $reservationId, DateTimeImmutable $startsAt, DateTimeImmutable $now): string
    {
        return $this->hold->execute($customerMembershipId, $serviceId, $reservationId, $startsAt, $now);
    }

    /** Give the session back through the Vouchers module. */
    public function release(string $voucherId, string $reservationId, DateTimeImmutable $now): void
    {
        $this->release->execute($voucherId, $reservationId, $now);
    }

    /** Leave the session used through the Vouchers module. */
    public function consume(string $voucherId, string $reservationId, DateTimeImmutable $now): void
    {
        $this->consume->execute($voucherId, $reservationId, $now);
    }
}
