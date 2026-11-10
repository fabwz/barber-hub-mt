<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\UseCases;

use DateTimeImmutable;
use Src\Payments\Payments\Application\Contracts\BarbershopScope;
use Src\Payments\Payments\Application\Contracts\PaymentLocator;
use Src\Payments\Payments\Application\Contracts\ProcessedNotices;
use Src\Payments\Payments\Application\DTOs\GatewayNotice;
use Src\Payments\Payments\Application\DTOs\NoticeResult;
use Src\Payments\Payments\Application\Exceptions\PaymentRequestRejectedException;
use Src\Payments\Payments\Application\Exceptions\PaymentServiceUnavailableException;
use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;

final readonly class ProcessGatewayNotice
{
    /** Receive the locator of payments, the barbershop scope, the record of applied notices, the payments and the synchronization with the provider. */
    public function __construct(
        private PaymentLocator $locator,
        private BarbershopScope $scope,
        private ProcessedNotices $notices,
        private PaymentRepository $payments,
        private SyncPaymentWithGateway $sync,
    ) {}

    /**
     * Apply a verified notice once (RF-22, PA-03, PA-05). The notice is only a trigger: it finds the payment, fixes its
     * barbershop, records the event and asks the provider for the real state of the payment, which settles it and confirms
     * the reservation when it was approved (PA-06). All of it, the record included, is one transaction that locks the
     * payment and the reservation, so a notice that arrives twice, at once or out of order leaves one payment and one
     * confirmed reservation, and one that fails halfway is not recorded and can be sent again. A payment of ours that the
     * provider does not know yet, or a notice about something else, changes nothing.
     *
     * @throws PaymentServiceUnavailableException when the provider cannot be asked; nothing is recorded
     * @throws PaymentRequestRejectedException when the provider does not accept the question; nothing is recorded
     */
    public function execute(GatewayNotice $notice, DateTimeImmutable $now): NoticeResult
    {
        if ($notice->reference === null) {
            return new NoticeResult($notice->provider, $notice->eventId, NoticeResult::IGNORED);
        }

        $location = $this->locator->locate($notice->provider, $notice->reference);

        if ($location === null) {
            return new NoticeResult($notice->provider, $notice->eventId, NoticeResult::UNKNOWN_PAYMENT);
        }

        $outcome = $this->scope->run($location->barbershopId, function () use ($notice, $location, $now): string {
            // The payment is locked before the event is recorded, so two notices of the same payment run in turn.
            $paymentId = new PaymentId($location->paymentId);

            if ($this->payments->findForUpdate($paymentId) === null) {
                return NoticeResult::UNKNOWN_PAYMENT;
            }

            if (! $this->notices->register($notice, $location->paymentId, $now)) {
                return NoticeResult::DUPLICATE;
            }

            $this->sync->execute($paymentId, $now);

            return NoticeResult::APPLIED;
        });

        return new NoticeResult($notice->provider, $notice->eventId, $outcome);
    }
}
