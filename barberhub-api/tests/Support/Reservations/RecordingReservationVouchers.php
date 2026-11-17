<?php

declare(strict_types=1);

namespace Tests\Support\Reservations;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\Contracts\ReservationVouchers;
use Throwable;

/** The vouchers port of the reservations for the unit tests: it records what was asked and can refuse to set a session aside. */
final class RecordingReservationVouchers implements ReservationVouchers
{
    public const string VOUCHER = '0192f0c4-1111-7000-8000-000000000001';

    /** The voucher the session comes from. */
    public string $voucherId = self::VOUCHER;

    /** What the next hold throws, as the vouchers module does when no voucher applies. */
    public ?Throwable $refuse = null;

    /** @var list<array{string, string, string}> The calls, each as the operation, the voucher or the customer, and the reservation. */
    public array $calls = [];

    /** @var list<array{string, DateTimeImmutable}> The service and the appointment of each hold. */
    public array $holds = [];

    /** Record the hold and return the voucher, or refuse. */
    public function hold(string $customerMembershipId, string $serviceId, string $reservationId, DateTimeImmutable $startsAt, DateTimeImmutable $now): string
    {
        $this->calls[] = ['hold', $customerMembershipId, $reservationId];
        $this->holds[] = [$serviceId, $startsAt];

        if ($this->refuse !== null) {
            throw $this->refuse;
        }

        return $this->voucherId;
    }

    /** Record the release. */
    public function release(string $voucherId, string $reservationId, DateTimeImmutable $now): void
    {
        $this->calls[] = ['release', $voucherId, $reservationId];
    }

    /** Record the consumption. */
    public function consume(string $voucherId, string $reservationId, DateTimeImmutable $now): void
    {
        $this->calls[] = ['consume', $voucherId, $reservationId];
    }

    /**
     * The operations recorded, without their arguments.
     *
     * @return list<string>
     */
    public function operations(): array
    {
        return array_column($this->calls, 0);
    }
}
