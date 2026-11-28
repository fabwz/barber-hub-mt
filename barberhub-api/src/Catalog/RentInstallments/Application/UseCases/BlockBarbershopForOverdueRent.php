<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Application\UseCases;

use DateTimeImmutable;
use Src\Catalog\RentInstallments\Application\Contracts\BarbershopBlockState;
use Src\Catalog\RentInstallments\Application\Contracts\BarbershopScope;
use Src\Catalog\RentInstallments\Domain\Services\RentCalendar;
use Src\Shared\Domain\Contracts\AuditLog;
use Src\Shared\Domain\ValueObjects\AuditAction;
use Src\Shared\Domain\ValueObjects\AuditEntry;

final readonly class BlockBarbershopForOverdueRent
{
    /** Receive the scope of a barbershop, its lock state, the count of pending months and the technical record. */
    public function __construct(
        private BarbershopScope $scope,
        private BarbershopBlockState $state,
        private CountPendingRentMonths $pendingMonths,
        private AuditLog $audit,
    ) {}

    /**
     * Block the barbershop when it has at least one overdue unpaid installment at this instant, and say whether it changed (RF-58, PL-07).
     * An installment is overdue from the day after it falls due in Costa Rica, so the block comes the day after and with no days of
     * grace. It runs as that barbershop, so it works with no session, and the block and its entry in the technical record (author
     * «system», PL-12) are written together. A barbershop that is already blocked is left as it is: nothing changes and no second entry
     * is written, so running it again is harmless. Blocking touches nothing else: reservations, payments, refunds and reminders go on (PL-10).
     */
    public function execute(string $barbershopId, DateTimeImmutable $now): bool
    {
        return $this->scope->run($barbershopId, function () use ($barbershopId, $now): bool {
            if ($this->state->lock($barbershopId) !== null || $this->pendingMonths->execute($barbershopId, $now) === 0 || ! $this->state->block($barbershopId, $now)) {
                return false;
            }

            $entry = AuditEntry::bySystem(AuditAction::BarbershopBlocked, 'barbershop', $barbershopId, ['blocked_at' => null], ['blocked_at' => RentCalendar::stamp($now)], $now);

            if ($entry !== null) {
                $this->audit->record($entry);
            }

            return true;
        });
    }
}
