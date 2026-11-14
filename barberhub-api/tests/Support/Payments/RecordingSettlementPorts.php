<?php

declare(strict_types=1);

namespace Tests\Support\Payments;

use DateTimeImmutable;
use Src\Payments\Payments\Application\Contracts\ReservationConfirmation;
use Src\Payments\Payments\Application\Contracts\VoucherActivation;

/** The two things an approved payment can settle, in one double: it remembers which reservations it confirmed and which vouchers it activated. */
final class RecordingSettlementPorts implements ReservationConfirmation, VoucherActivation
{
    /** @var list<string> */
    public array $confirmed = [];

    /** @var list<string> */
    public array $activated = [];

    /** Whether the voucher can be activated; false makes the payment one to give back. */
    public bool $activationWorks = true;

    /** Remember the reservation as confirmed. */
    public function confirm(string $reservationId, DateTimeImmutable $now): bool
    {
        $this->confirmed[] = $reservationId;

        return true;
    }

    /** Remember the voucher as activated, or refuse as the test says. */
    public function activate(string $voucherId, DateTimeImmutable $now): bool
    {
        $this->activated[] = $voucherId;

        return $this->activationWorks;
    }
}
