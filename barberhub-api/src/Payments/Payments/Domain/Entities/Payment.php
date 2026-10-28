<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Domain\Entities;

use DateTimeImmutable;
use LogicException;
use Src\Payments\Payments\Domain\Exceptions\PaymentTransitionNotAllowedException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;
use Src\Payments\Payments\Domain\ValueObjects\ProviderAmount;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Money;

final class Payment
{
    /** Longest reason of a failure that is stored. */
    public const int MAX_FAILURE_REASON_LENGTH = 255;

    /** Hold the full state of a payment. */
    private function __construct(
        public readonly PaymentId $id,
        public readonly BarbershopId $barbershopId,
        public readonly ReservationId $reservationId,
        public readonly PaymentProvider $provider,
        public readonly PaymentMethod $method,
        public readonly Money $amount,
        public private(set) ?string $providerPaymentId,
        public private(set) ?ProviderAmount $providerAmount,
        public private(set) PaymentStatus $status,
        public private(set) ?string $failureReason,
        public readonly DateTimeImmutable $createdAt,
        public private(set) DateTimeImmutable $updatedAt,
        public private(set) bool $refundPending = false,
        public private(set) ?string $providerCaptureId = null,
    ) {}

    /**
     * Create a payment. It starts pending with the provider and the amount fixed now: the provider the barbershop has at this
     * moment completes it, even if the owner changes it later (PA-02). The provider does not know the payment yet.
     */
    public static function create(
        PaymentId $id,
        BarbershopId $barbershopId,
        ReservationId $reservationId,
        PaymentProvider $provider,
        PaymentMethod $method,
        Money $amount,
        DateTimeImmutable $at,
    ): self {
        if ($amount->colones <= 0) {
            throw new InvalidValueException('payments.amount_invalid');
        }

        return new self($id, $barbershopId, $reservationId, $provider, $method, $amount, null, null, PaymentStatus::Pending, null, $at, $at);
    }

    /** Rebuild a payment from its stored state. */
    public static function reconstitute(
        PaymentId $id,
        BarbershopId $barbershopId,
        ReservationId $reservationId,
        PaymentProvider $provider,
        PaymentMethod $method,
        Money $amount,
        ?string $providerPaymentId,
        ?ProviderAmount $providerAmount,
        PaymentStatus $status,
        ?string $failureReason,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        bool $refundPending = false,
        ?string $providerCaptureId = null,
    ): self {
        return new self($id, $barbershopId, $reservationId, $provider, $method, $amount, $providerPaymentId, $providerAmount, $status, $failureReason, $createdAt, $updatedAt, $refundPending, $providerCaptureId);
    }

    /**
     * Keep the identifier the provider gave to the charge and what it charged in its own currency. It happens once, while the
     * payment is pending.
     *
     * @throws PaymentTransitionNotAllowedException when the payment is not pending
     * @throws LogicException when the charge was already registered
     */
    public function registerCharge(string $providerPaymentId, ProviderAmount $providerAmount, DateTimeImmutable $at): void
    {
        if ($this->status !== PaymentStatus::Pending) {
            throw PaymentTransitionNotAllowedException::create();
        }

        if ($this->providerPaymentId !== null) {
            throw new LogicException('The charge of the payment is already registered.');
        }

        if (trim($providerPaymentId) === '') {
            throw new InvalidValueException('payments.provider_payment_id_invalid');
        }

        $this->providerPaymentId = $providerPaymentId;
        $this->providerAmount = $providerAmount;
        $this->updatedAt = $at;
    }

    /**
     * The provider confirmed the money, with the identifier of the capture when it has one. Only a charge that exists in the
     * provider can be approved.
     *
     * @throws PaymentTransitionNotAllowedException
     */
    public function approve(DateTimeImmutable $at, ?string $captureId = null): void
    {
        if (! $this->status->canChangeTo(PaymentStatus::Approved)) {
            throw PaymentTransitionNotAllowedException::create();
        }

        if ($this->providerPaymentId === null) {
            throw new LogicException('A payment that was never charged cannot be approved.');
        }

        $this->changeTo(PaymentStatus::Approved, $at);
        $this->providerCaptureId = $captureId === null || trim($captureId) === '' ? null : trim($captureId);
    }

    /**
     * The provider refused the payment: a declined card, no funds.
     *
     * @throws PaymentTransitionNotAllowedException
     */
    public function reject(?string $reason, DateTimeImmutable $at): void
    {
        $this->changeTo(PaymentStatus::Rejected, $at, $reason);
    }

    /**
     * The payment could not be completed for a reason other than a refusal: the charge expired or the provider errored.
     *
     * @throws PaymentTransitionNotAllowedException
     */
    public function fail(?string $reason, DateTimeImmutable $at): void
    {
        $this->changeTo(PaymentStatus::Failed, $at, $reason);
    }

    /**
     * The money went back to the customer. Only an approved payment can be refunded.
     *
     * @throws PaymentTransitionNotAllowedException
     */
    public function refund(DateTimeImmutable $at): void
    {
        $this->changeTo(PaymentStatus::Refunded, $at);
        $this->refundPending = false;
    }

    /**
     * The provider approved the money after the reservation could no longer be confirmed (PA-07): the customer paid for a
     * space the system cannot give, so the payment stays approved and is marked as one to give back.
     *
     * @throws PaymentTransitionNotAllowedException when the payment is not approved
     */
    public function markRefundPending(DateTimeImmutable $at): void
    {
        if ($this->status !== PaymentStatus::Approved) {
            throw PaymentTransitionNotAllowedException::create();
        }

        $this->refundPending = true;
        $this->updatedAt = $at;
    }

    /** Apply a change the statuses allow; a rejection or a failure keeps its reason. */
    private function changeTo(PaymentStatus $target, DateTimeImmutable $at, ?string $reason = null): void
    {
        if (! $this->status->canChangeTo($target)) {
            throw PaymentTransitionNotAllowedException::create();
        }

        $this->status = $target;
        $this->failureReason = $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, self::MAX_FAILURE_REASON_LENGTH);
        $this->updatedAt = $at;
    }
}
