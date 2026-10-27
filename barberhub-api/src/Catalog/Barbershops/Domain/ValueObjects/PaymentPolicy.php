<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\ValueObjects;

use Src\Shared\Domain\Exceptions\InvalidValueException;

/** How a barbershop charges and cancels: the mode, the deposit, the free-cancellation deadline and the gateway (PA-01, PA-02, CA-01). */
final readonly class PaymentPolicy
{
    public const int MIN_DEPOSIT_PERCENTAGE = 10;

    public const int MAX_DEPOSIT_PERCENTAGE = 100;

    public const int MIN_DEADLINE_HOURS = 0;

    public const int MAX_DEADLINE_HOURS = 720;

    public const int DEFAULT_DEADLINE_HOURS = 24;

    /**
     * Check the policy as a whole: the percentage exists only with a deposit and stays between 10 and 100, the deadline
     * stays between 0 and 720 hours, and a mode that charges needs a gateway.
     */
    private function __construct(
        public PaymentMode $mode,
        public ?int $depositPercentage,
        public int $cancellationDeadlineHours,
        public ?PaymentGateway $gateway,
    ) {
        if ($mode === PaymentMode::Deposit && $depositPercentage === null) {
            throw new InvalidValueException('barbershops.deposit_percentage_required');
        }

        if ($mode !== PaymentMode::Deposit && $depositPercentage !== null) {
            throw new InvalidValueException('barbershops.deposit_percentage_not_allowed');
        }

        if ($depositPercentage !== null && ($depositPercentage < self::MIN_DEPOSIT_PERCENTAGE || $depositPercentage > self::MAX_DEPOSIT_PERCENTAGE)) {
            throw new InvalidValueException('barbershops.deposit_percentage_invalid', ['min' => self::MIN_DEPOSIT_PERCENTAGE, 'max' => self::MAX_DEPOSIT_PERCENTAGE]);
        }

        if ($cancellationDeadlineHours < self::MIN_DEADLINE_HOURS || $cancellationDeadlineHours > self::MAX_DEADLINE_HOURS) {
            throw new InvalidValueException('barbershops.cancellation_deadline_invalid', ['min' => self::MIN_DEADLINE_HOURS, 'max' => self::MAX_DEADLINE_HOURS]);
        }

        if ($mode->charges() && $gateway === null) {
            throw new InvalidValueException('barbershops.payment_gateway_required');
        }
    }

    /** Build a policy, rejecting any combination that breaks its rules. */
    public static function of(PaymentMode $mode, ?int $depositPercentage, int $cancellationDeadlineHours, ?PaymentGateway $gateway): self
    {
        return new self($mode, $depositPercentage, $cancellationDeadlineHours, $gateway);
    }

    /** The policy a new barbershop starts with: it charges nothing, has no gateway and cancels free up to 24 hours before. */
    public static function initial(): self
    {
        return new self(PaymentMode::None, null, self::DEFAULT_DEADLINE_HOURS, null);
    }
}
