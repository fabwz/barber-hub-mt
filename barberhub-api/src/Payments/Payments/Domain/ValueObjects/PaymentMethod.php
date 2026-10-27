<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Domain\ValueObjects;

/** How the customer pays: with a card or with SINPE Móvil; each provider admits some of them (PA-02). */
enum PaymentMethod: string
{
    case Card = 'card';
    case SinpeMovil = 'sinpe_movil';
}
