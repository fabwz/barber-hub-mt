<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Domain\Entities;

use DateTimeImmutable;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\BalanceAboveTotalException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\InsufficientBalanceException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherAlreadyActiveException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherExpiredException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotActiveException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\CustomerMembershipId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\PurchasedVoucherId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\ReservationId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\SessionBalance;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\VoucherStatus;
use Src\Vouchers\Shared\Domain\ValueObjects\SessionCount;
use Src\Vouchers\Shared\Domain\ValueObjects\ValidityDays;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherName;

/**
 * A voucher a customer bought: what was bought (a copy of the definition at that moment), the sessions left and the validity.
 * Its invariants are that the balance is never negative and never above the sessions bought, and that every change of the
 * balance leaves an entry in the record, so the sum of the entries is the balance (BO-03, BO-09). The balance only changes
 * through hold() and release(); the entries are written by the repository, which reads them with pullMovements().
 */
final class PurchasedVoucher
{
    /** @var list<VoucherMovement> */
    private array $movements = [];

    /** Hold the full state of a purchased voucher. */
    private function __construct(
        public readonly PurchasedVoucherId $id,
        public readonly BarbershopId $barbershopId,
        public readonly CustomerMembershipId $customerMembershipId,
        public readonly VoucherDefinitionId $voucherDefinitionId,
        public readonly VoucherName $name,
        public readonly Money $price,
        public readonly SessionCount $sessionsCount,
        public readonly ValidityDays $validityDays,
        public private(set) VoucherStatus $status,
        public private(set) ?DateTimeImmutable $activatedAt,
        public private(set) ?DateTimeImmutable $expiresAt,
        public private(set) SessionBalance $balance,
        public readonly DateTimeImmutable $createdAt,
        public private(set) DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Start a purchase. The voucher keeps what the definition said at this moment, so editing or withdrawing the definition does
     * not change it; it has no sessions and no validity until the payment is confirmed (BO-02).
     */
    public static function purchase(
        PurchasedVoucherId $id,
        BarbershopId $barbershopId,
        CustomerMembershipId $customerMembershipId,
        VoucherDefinitionId $voucherDefinitionId,
        VoucherName $name,
        Money $price,
        SessionCount $sessionsCount,
        ValidityDays $validityDays,
        DateTimeImmutable $at,
    ): self {
        if ($price->colones <= 0) {
            throw new InvalidValueException('vouchers.price_invalid');
        }

        return new self($id, $barbershopId, $customerMembershipId, $voucherDefinitionId, $name, $price, $sessionsCount, $validityDays, VoucherStatus::Pending, null, null, SessionBalance::zero(), $at, $at);
    }

    /** Rebuild a voucher from its stored state, which the table keeps consistent with the same rules. */
    public static function reconstitute(
        PurchasedVoucherId $id,
        BarbershopId $barbershopId,
        CustomerMembershipId $customerMembershipId,
        VoucherDefinitionId $voucherDefinitionId,
        VoucherName $name,
        Money $price,
        SessionCount $sessionsCount,
        ValidityDays $validityDays,
        VoucherStatus $status,
        ?DateTimeImmutable $activatedAt,
        ?DateTimeImmutable $expiresAt,
        SessionBalance $balance,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        if ($balance->value > $sessionsCount->value) {
            throw new InvalidValueException('vouchers.balance_above_total');
        }

        return new self($id, $barbershopId, $customerMembershipId, $voucherDefinitionId, $name, $price, $sessionsCount, $validityDays, $status, $activatedAt, $expiresAt, $balance, $createdAt, $updatedAt);
    }

    /**
     * The payment was confirmed: the voucher gets all its sessions and its validity counts from this moment (BO-02, RF-27).
     *
     * @throws VoucherAlreadyActiveException when it was already activated
     */
    public function activate(DateTimeImmutable $at): void
    {
        if ($this->status !== VoucherStatus::Pending) {
            throw VoucherAlreadyActiveException::create();
        }

        $this->status = VoucherStatus::Active;
        $this->activatedAt = $at;
        $this->expiresAt = $this->validityDays->endingFrom($at);
        $this->balance = SessionBalance::of($this->sessionsCount->value);
        $this->updatedAt = $at;
        $this->movements[] = VoucherMovement::purchase($this->sessionsCount, $at);
    }

    /**
     * Set a session aside for a reservation (BO-04). The validity is checked at the instant given, which is the one the use case
     * decides is the one that must fall inside it (BO-06), and it ends exactly at the expiry instant.
     *
     * @throws VoucherNotActiveException when the payment is not confirmed
     * @throws VoucherExpiredException when the voucher is not valid at the instant
     * @throws InsufficientBalanceException when no session is left
     */
    public function hold(ReservationId $reservation, DateTimeImmutable $at): void
    {
        $this->assertActive();

        if (! $this->isValidAt($at)) {
            throw VoucherExpiredException::create();
        }

        $this->balance = $this->balance->decrement();
        $this->updatedAt = $at;
        $this->movements[] = VoucherMovement::hold($reservation, $at);
    }

    /**
     * Give the session of a reservation back: the customer cancelled in time or the barbershop cancelled (BO-05). It is given
     * back even after the validity ended; what the voucher shows then is still zero (BO-07).
     *
     * @throws VoucherNotActiveException when the payment is not confirmed
     * @throws BalanceAboveTotalException when the voucher already has all its sessions
     */
    public function release(ReservationId $reservation, DateTimeImmutable $at): void
    {
        $this->assertActive();

        $this->balance = $this->balance->increment($this->sessionsCount);
        $this->updatedAt = $at;
        $this->movements[] = VoucherMovement::release($reservation, $at);
    }

    /**
     * The session of a reservation stays used: the customer attended or missed it, or cancelled late (BO-05). The session was
     * taken when the reservation was confirmed, so the balance does not change; the entry says how it ended.
     *
     * @throws VoucherNotActiveException when the payment is not confirmed
     */
    public function consume(ReservationId $reservation, DateTimeImmutable $at): void
    {
        $this->assertActive();

        $this->updatedAt = $at;
        $this->movements[] = VoucherMovement::consume($reservation, $at);
    }

    /** Tell whether a reservation can use the voucher at the instant: it is paid and the instant is before the expiry (BO-06). */
    public function isValidAt(DateTimeImmutable $at): bool
    {
        return $this->status === VoucherStatus::Active && $this->expiresAt !== null && $at < $this->expiresAt;
    }

    /** The sessions the voucher shows at the instant: an expired or unpaid voucher shows none, though its sessions are not erased (BO-07). */
    public function balanceAt(DateTimeImmutable $at): SessionBalance
    {
        return $this->isValidAt($at) ? $this->balance : SessionBalance::zero();
    }

    /**
     * The entries the changes since it was created or read have left, handed over once to be written to the record.
     *
     * @return list<VoucherMovement>
     */
    public function pullMovements(): array
    {
        $movements = $this->movements;
        $this->movements = [];

        return $movements;
    }

    private function assertActive(): void
    {
        if ($this->status !== VoucherStatus::Active) {
            throw VoucherNotActiveException::create();
        }
    }
}
