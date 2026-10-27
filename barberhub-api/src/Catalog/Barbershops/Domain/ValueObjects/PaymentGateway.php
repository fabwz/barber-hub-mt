<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\ValueObjects;

/** The single gateway a barbershop collects through: ONVO Pay or PayPal (PA-02). */
enum PaymentGateway: string
{
    case Onvo = 'onvo';
    case Paypal = 'paypal';
}
