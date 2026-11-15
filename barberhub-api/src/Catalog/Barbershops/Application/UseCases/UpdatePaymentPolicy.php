<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\UseCases;

use DateTimeImmutable;
use Src\Catalog\Barbershops\Application\DTOs\UpdatePaymentPolicyCommand;
use Src\Catalog\Barbershops\Domain\Contracts\BarbershopRepository;
use Src\Catalog\Barbershops\Domain\Exceptions\BarbershopNotFoundException;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentGateway;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentMode;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentPolicy;
use Src\Shared\Domain\Contracts\AuditLog;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Domain\ValueObjects\AuditAction;
use Src\Shared\Domain\ValueObjects\AuditEntry;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class UpdatePaymentPolicy
{
    /** Receive the barbershop repository, the context that tells which barbershop is current, the technical record and the transaction port. */
    public function __construct(
        private BarbershopRepository $barbershops,
        private BarbershopContext $context,
        private AuditLog $audit,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Change the payment policy of the current barbershop (RF-08). A field that does not come keeps its current value, except
     * the percentage, which belongs to the deposit: it is kept only while the mode stays a deposit. The policy validates itself,
     * so a deposit of 5 % or 120 %, a deposit without percentage or a charge without gateway is rejected. The change applies
     * to the reservations and charges made from now on, never to those already made (PA-02, CA-01). The change and its entries in the
     * technical record (SE-04, the policy and the gateway apart) are written in one transaction: if one fails, none stays.
     */
    public function execute(UpdatePaymentPolicyCommand $command, string $actorUserId, DateTimeImmutable $now): PaymentPolicy
    {
        $barbershop = $this->barbershops->find($this->context->current()) ?? throw BarbershopNotFoundException::create();
        $current = $barbershop->paymentPolicy;

        $mode = $command->mode === null ? $current->mode : PaymentMode::from($command->mode);
        $policy = PaymentPolicy::of(
            $mode,
            $command->depositPercentage ?? ($mode === PaymentMode::Deposit ? $current->depositPercentage : null),
            $command->cancellationDeadlineHours ?? $current->cancellationDeadlineHours,
            $command->gateway === null ? $current->gateway : PaymentGateway::from($command->gateway),
        );

        $this->transaction->run(function () use ($barbershop, $current, $policy, $actorUserId, $now): void {
            $this->barbershops->save($barbershop->withPaymentPolicy($policy));

            foreach ([AuditAction::PaymentPolicyUpdated, AuditAction::PaymentGatewayUpdated] as $action) {
                $entry = AuditEntry::of(new UserId($actorUserId), $action, 'barbershop', $barbershop->id->value, self::snapshot($current), self::snapshot($policy), $now);

                if ($entry !== null) {
                    $this->audit->record($entry);
                }
            }
        });

        return $policy;
    }

    /**
     * The fields of a policy as the technical record names them.
     *
     * @return array<string, int|string|null>
     */
    private static function snapshot(PaymentPolicy $policy): array
    {
        return [
            'mode' => $policy->mode->value,
            'deposit_percentage' => $policy->depositPercentage,
            'cancellation_deadline_hours' => $policy->cancellationDeadlineHours,
            'gateway' => $policy->gateway?->value,
        ];
    }
}
