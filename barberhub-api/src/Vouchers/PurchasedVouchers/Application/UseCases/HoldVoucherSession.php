<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\UseCases;

use DateTimeImmutable;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\CoveringDefinitions;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\InsufficientBalanceException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherExpiredException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotApplicableException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\CustomerMembershipId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\ReservationId;

final readonly class HoldVoucherSession
{
    /** Receive the voucher repository and the port that tells which definitions cover a service. */
    public function __construct(
        private PurchasedVoucherRepository $vouchers,
        private CoveringDefinitions $covering,
    ) {}

    /**
     * Set a session of a voucher of the customer aside for a reservation and return the voucher it was taken from (RF-28, BO-04).
     * The voucher is chosen among the paid ones of the customer in the current barbershop, never those of another (MT-04, BO-07),
     * in this order: if the customer has none paid, there is no balance; if none covers the service, none applies and no difference
     * is charged (BO-06); if none of those is valid at the appointment, the voucher is expired (BO-06, BO-07); of the valid ones, the
     * one that expires first goes first (BO-08). The session is taken with the atomic and conditional update of the repository, so
     * a voucher whose last session another reservation took in the meantime is skipped for the next one, and when no voucher
     * has a session left the balance is insufficient (BO-03). It must run inside the transaction of the reservation.
     *
     * @throws InsufficientBalanceException
     * @throws VoucherNotApplicableException
     * @throws VoucherExpiredException
     */
    public function execute(string $customerMembershipId, string $serviceId, string $reservationId, DateTimeImmutable $startsAt, DateTimeImmutable $now): string
    {
        $paid = $this->vouchers->activeOfCustomer(new CustomerMembershipId($customerMembershipId));

        if ($paid === []) {
            throw InsufficientBalanceException::create();
        }

        $definitions = $this->covering->idsCovering($serviceId);
        $covering = array_values(array_filter($paid, fn (PurchasedVoucher $voucher): bool => in_array($voucher->voucherDefinitionId->value, $definitions, true)));

        if ($covering === []) {
            throw VoucherNotApplicableException::create();
        }

        $valid = array_values(array_filter($covering, fn (PurchasedVoucher $voucher): bool => $voucher->isValidAt($startsAt)));

        if ($valid === []) {
            throw VoucherExpiredException::create();
        }

        foreach ($valid as $voucher) {
            try {
                $this->vouchers->holdSession($voucher->id, new ReservationId($reservationId), $startsAt, $now);

                return $voucher->id->value;
            } catch (InsufficientBalanceException) {
                continue;
            }
        }

        throw InsufficientBalanceException::create();
    }
}
