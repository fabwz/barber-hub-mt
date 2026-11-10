<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Contracts;

use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;

interface GatewayNoticeVerifiers
{
    /** The verifier of a provider. */
    public function for(PaymentProvider $provider): GatewayNoticeVerifier;
}
