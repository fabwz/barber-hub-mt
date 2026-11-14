<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Contracts;

use DateTimeImmutable;

interface VoucherActivation
{
    /**
     * Activate the voucher whose payment was confirmed: it gets its sessions and its validity counts from the instant (BO-02). It
     * answers false, instead of failing, when the voucher cannot be activated, which is a payment to give back. Called inside
     * the transaction of the payment, so both are saved or none.
     */
    public function activate(string $voucherId, DateTimeImmutable $now): bool;
}
