<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\UseCases;

use DateTimeImmutable;
use Src\Payments\Payments\Application\Exceptions\GatewayUnavailableException;
use Src\Payments\Payments\Application\Exceptions\PaymentServiceUnavailableException;
use Src\Payments\Payments\Application\Exceptions\RefundRefusedException;
use Src\Payments\Payments\Application\PaymentGatewayResolver;
use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\Exceptions\PaymentNotFoundException;
use Src\Payments\Payments\Domain\Exceptions\PaymentTransitionNotAllowedException;
use Src\Payments\Payments\Domain\Exceptions\VoucherPaymentNotRefundableException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;
use Src\Shared\Domain\Contracts\AuditLog;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Domain\ValueObjects\AuditAction;
use Src\Shared\Domain\ValueObjects\AuditEntry;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class RefundPayment
{
    /** Receive the payment repository, the gateway resolver, the technical record and the transaction port. */
    public function __construct(
        private PaymentRepository $payments,
        private PaymentGatewayResolver $gateways,
        private AuditLog $audit,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Give the whole payment back through the gateway it was charged with (PA-08). Only an approved payment can be refunded.
     * If the provider accepts, the payment becomes refunded and loses the mark of one to give back; if it refuses, the
     * payment stays approved and the reason reaches the owner. The refund and its entry in the technical record (SE-04: who, when, the
     * status before and after and the amount given back) are written in one transaction: if one fails, none stays.
     *
     * @throws PaymentNotFoundException
     * @throws PaymentTransitionNotAllowedException when the payment is not approved
     * @throws VoucherPaymentNotRefundableException when the payment is of a voucher, which is not refundable (BO-07)
     * @throws RefundRefusedException
     * @throws PaymentServiceUnavailableException
     */
    public function execute(string $paymentId, string $actorUserId, DateTimeImmutable $now): Payment
    {
        $payment = $this->payments->find(new PaymentId($paymentId)) ?? throw PaymentNotFoundException::create();

        if ($payment->isOfVoucher()) {
            throw VoucherPaymentNotRefundableException::create();
        }

        if (! $payment->status->canChangeTo(PaymentStatus::Refunded) || $payment->providerPaymentId === null) {
            throw PaymentTransitionNotAllowedException::create();
        }

        try {
            $result = $this->gateways->forPayment($payment)->refund($payment->providerPaymentId, $payment->amount, $payment->id);
        } catch (GatewayUnavailableException) {
            throw PaymentServiceUnavailableException::create();
        }

        if (! $result->accepted) {
            throw RefundRefusedException::because($result->failureReason);
        }

        $previous = $payment->status;
        $this->transaction->run(function () use ($payment, $previous, $actorUserId, $now): void {
            $payment->refund($now);
            $this->payments->save($payment);

            $entry = AuditEntry::of(
                new UserId($actorUserId),
                AuditAction::PaymentRefunded,
                'payment',
                $payment->id->value,
                ['status' => $previous->value, 'refunded_amount' => null],
                ['status' => $payment->status->value, 'refunded_amount' => $payment->amount->colones],
                $now,
            );

            if ($entry !== null) {
                $this->audit->record($entry);
            }
        });

        return $payment;
    }
}
