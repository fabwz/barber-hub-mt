<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Notices;

use Closure;
use Src\Payments\Payments\Application\Contracts\GatewayNoticeVerifier;
use Src\Payments\Payments\Application\Contracts\GatewayNoticeVerifiers;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;

final readonly class ConfiguredNoticeVerifiers implements GatewayNoticeVerifiers
{
    /**
     * Receive how to build the verifier of each provider, by the value of PaymentProvider. They are built when asked for, so a
     * configuration mistake of one provider never reaches the notices of the other.
     *
     * @param  array<string, Closure(): GatewayNoticeVerifier>  $factories
     */
    public function __construct(private array $factories) {}

    public function for(PaymentProvider $provider): GatewayNoticeVerifier
    {
        return ($this->factories[$provider->value])();
    }
}
