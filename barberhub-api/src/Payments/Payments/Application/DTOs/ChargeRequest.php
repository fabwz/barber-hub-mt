<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PurchasedVoucherId;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;
use Src\Shared\Domain\ValueObjects\Money;

/**
 * What the gateway needs to create a charge: the amount in colones, the method, what the customer gave for it and the references to
 * find it again: the reservation it pays for or, for a voucher (RF-27), the purchased voucher, never both.
 */
final readonly class ChargeRequest
{
    public function __construct(
        public PaymentId $paymentId,
        public ?ReservationId $reservationId,
        public Money $amount,
        public PaymentMethod $method,
        public ?PaymentDetails $details = null,
        public ?PurchasedVoucherId $voucherId = null,
    ) {}

    /** The identifier of what is being paid, the reservation or the voucher, as the provider keeps it to find the payment again. */
    public function subjectId(): string
    {
        return $this->reservationId === null ? ($this->voucherId === null ? '' : $this->voucherId->value) : $this->reservationId->value;
    }
}
