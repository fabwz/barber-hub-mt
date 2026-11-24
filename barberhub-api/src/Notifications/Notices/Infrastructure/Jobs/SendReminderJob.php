<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Jobs;

use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Src\Notifications\Notices\Application\UseCases\SendReminder;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;

/**
 * Sends one reminder when it is due (RF-33). It waits in the queue until `$scheduledFor`, so it survives a restart of the workers. It carries
 * the instant it was left for: if the reservation was rescheduled the reminder row has another instant and this task finds nothing to send.
 */
final class SendReminderJob implements ShouldQueueAfterCommit
{
    use ThrottledNoticeJob;

    /** @param  string  $kind  The value of a ReminderKind. */
    public function __construct(
        public readonly string $reservationId,
        public readonly string $barbershopId,
        public readonly string $kind,
        public readonly DateTimeImmutable $scheduledFor,
    ) {}

    /** Send the reminder as its barbershop, in its transaction: a failure of the channel undoes the claim, so the retry finds it unsent. */
    public function handle(BarbershopProcessScope $scope, SendReminder $send, Clock $clock): void
    {
        $scope->run($this->barbershopId, fn (): bool => $send->execute($this->reservationId, ReminderKind::from($this->kind), $this->scheduledFor, $clock->now()));
    }
}
