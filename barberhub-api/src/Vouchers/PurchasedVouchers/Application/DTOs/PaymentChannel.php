<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\DTOs;

final readonly class PaymentChannel
{
    /**
     * Carry the gateway the barbershop collects through and the methods it admits, by their names in the API.
     *
     * @param  list<string>  $methods
     */
    public function __construct(public string $gateway, public array $methods) {}
}
