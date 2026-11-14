<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Contracts;

use DateTimeImmutable;
use Src\Payments\Payments\Application\DTOs\PayableVoucher;
use Src\Payments\Payments\Domain\Exceptions\PaymentNotFoundException;

interface PayableVouchers
{
    /**
     * Start the purchase of a voucher by a person in the current barbershop: the voucher on sale is locked, the customer role is
     * created if they have none (UP-09) and a voucher waiting for its payment is registered with a copy of what is sold (BO-02). A
     * person who already has one waiting for the same voucher gets that one. Everything joins the transaction of the caller, so a
     * rollback takes the role and the voucher with it.
     *
     * @throws PaymentNotFoundException When the voucher is not on sale in the barbershop, does not exist or is of another barbershop (MT-03).
     */
    public function begin(string $userId, string $voucherDefinitionId, DateTimeImmutable $now): PayableVoucher;

    /**
     * The purchased voucher of the current barbershop that belongs to the customer.
     *
     * @throws PaymentNotFoundException When it does not exist, is of another barbershop or of another customer (UP-06).
     */
    public function ofCustomer(string $userId, string $voucherId): PayableVoucher;
}
