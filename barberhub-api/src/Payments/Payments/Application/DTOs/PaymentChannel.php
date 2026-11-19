<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

use Src\Payments\Payments\Application\Contracts\PaymentGateway;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;

/** The provider a reservation is paid through and the methods that provider admits (PA-02). Public data: it carries no credentials. */
final readonly class PaymentChannel
{
    /**
     * Carry the provider and the methods it declares.
     *
     * @param  list<PaymentMethod>  $methods
     */
    public function __construct(public PaymentProvider $provider, public array $methods) {}

    /** The channel of a gateway: its provider and every method it says it supports. */
    public static function ofGateway(PaymentGateway $gateway): self
    {
        return new self($gateway->provider(), array_values(array_filter(PaymentMethod::cases(), fn (PaymentMethod $method): bool => $gateway->supports($method))));
    }
}
