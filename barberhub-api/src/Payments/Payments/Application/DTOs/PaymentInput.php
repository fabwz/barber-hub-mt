<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

/** A piece of data a payment method can ask of the customer; each gateway says which ones its methods need (PA-02). */
enum PaymentInput: string
{
    case CardToken = 'card_token';
    case Phone = 'phone';
    case Identification = 'identification';
    case IdentificationType = 'identification_type';
}
