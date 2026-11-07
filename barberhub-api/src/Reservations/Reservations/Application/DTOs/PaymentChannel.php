<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

final readonly class PaymentChannel
{
    /**
     * Carry the gateway a reservation is paid through and the methods it admits, by their names in the API.
     *
     * @param  list<string>  $methods
     */
    public function __construct(public string $gateway, public array $methods) {}
}
