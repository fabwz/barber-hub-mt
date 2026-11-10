<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Contracts;

use Src\Payments\Payments\Application\DTOs\GatewayNotice;
use Src\Payments\Payments\Application\Exceptions\NoticeNotVerifiedException;
use Src\Payments\Payments\Application\Exceptions\PaymentServiceUnavailableException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;

/**
 * Verifies where a notice comes from, with the way of its own provider (PA-04). There is one adapter per provider in
 * Infrastructure. Nothing is read from the notice before it is verified.
 */
interface GatewayNoticeVerifier
{
    /** The provider this adapter verifies. */
    public function provider(): PaymentProvider;

    /**
     * Verify the notice and reduce it to what the system uses.
     *
     * @param  array<string, string>  $headers  Of the request, by lowercase name.
     * @param  string  $rawBody  Exactly as it was received.
     *
     * @throws NoticeNotVerifiedException When the origin cannot be verified; nobody is told why.
     * @throws PaymentServiceUnavailableException When it cannot be verified now: the verification is not configured or the provider that verifies it does not answer.
     */
    public function verify(array $headers, string $rawBody): GatewayNotice;
}
