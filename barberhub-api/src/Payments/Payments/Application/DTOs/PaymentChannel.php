<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

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
}
