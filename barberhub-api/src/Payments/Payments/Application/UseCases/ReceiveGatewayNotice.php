<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\UseCases;

use DateTimeImmutable;
use Src\Payments\Payments\Application\Contracts\GatewayNoticeVerifiers;
use Src\Payments\Payments\Application\DTOs\NoticeResult;
use Src\Payments\Payments\Application\Exceptions\NoticeNotVerifiedException;
use Src\Payments\Payments\Application\Exceptions\PaymentRequestRejectedException;
use Src\Payments\Payments\Application\Exceptions\PaymentServiceUnavailableException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;

final readonly class ReceiveGatewayNotice
{
    /** Receive the verifiers of each provider and the use case that applies a verified notice. */
    public function __construct(private GatewayNoticeVerifiers $verifiers, private ProcessGatewayNotice $process) {}

    /**
     * Verify where the notice comes from with the way of its provider and, only then, apply it (RF-22, PA-04). Nothing is
     * changed before the verification succeeds.
     *
     * @param  array<string, string>  $headers  Of the request, by lowercase name.
     * @param  string  $rawBody  Exactly as it was received.
     *
     * @throws NoticeNotVerifiedException when the origin cannot be verified
     * @throws PaymentServiceUnavailableException when it cannot be verified or applied now; the provider should send it again
     * @throws PaymentRequestRejectedException when the provider does not accept the question about the payment
     */
    public function execute(PaymentProvider $provider, array $headers, string $rawBody, DateTimeImmutable $now): NoticeResult
    {
        $notice = $this->verifiers->for($provider)->verify($headers, $rawBody);

        return $this->process->execute($notice, $now);
    }
}
