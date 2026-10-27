<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Domain\ValueObjects;

/** The company that processes a payment: ONVO Pay or PayPal (PA-02). */
enum PaymentProvider: string
{
    case Onvo = 'onvo';
    case Paypal = 'paypal';
}
