<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Domain\ValueObjects;

use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\BalanceAboveTotalException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\InsufficientBalanceException;
use Src\Vouchers\Shared\Domain\ValueObjects\SessionCount;

/** The sessions a purchased voucher has left. It can never be negative: it is built, and changes, only through this type (BO-03). */
final readonly class SessionBalance
{
    private function __construct(public int $value) {}

    /** Build a balance of zero or more sessions. */
    public static function of(int $value): self
    {
        if ($value < 0) {
            throw new InvalidValueException('vouchers.balance_negative');
        }

        return new self($value);
    }

    /** No sessions left. */
    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * One session less.
     *
     * @throws InsufficientBalanceException when none is left
     */
    public function decrement(): self
    {
        if ($this->value === 0) {
            throw InsufficientBalanceException::create();
        }

        return new self($this->value - 1);
    }

    /**
     * One session more, up to the sessions the voucher was bought with.
     *
     * @throws BalanceAboveTotalException when the balance is already the whole voucher
     */
    public function increment(SessionCount $total): self
    {
        if ($this->value >= $total->value) {
            throw BalanceAboveTotalException::create();
        }

        return new self($this->value + 1);
    }

    /** Tell whether no session is left. */
    public function isZero(): bool
    {
        return $this->value === 0;
    }

    /** Compare by value. */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
