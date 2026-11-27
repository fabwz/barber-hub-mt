<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Jobs;

use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Src\Notifications\Notices\Application\DTOs\NoticeCommand;
use Src\Notifications\Notices\Application\DTOs\NoticeType;
use Src\Notifications\Notices\Application\UseCases\SendNotice;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;

/**
 * Sends the notice of a confirmation, an offer, a cancellation or a reschedule (RF-34). It is left in the queue only after the transaction
 * that caused it commits, so the notice is never about something that was undone, and a failure of the channel can never undo the reservation.
 * It carries its barbershop because a worker has no request to take it from.
 */
final class SendNoticeJob implements ShouldQueueAfterCommit
{
    use ThrottledNoticeJob;

    public function __construct(
        public readonly string $barbershopId,
        public readonly NoticeType $type,
        public readonly ?string $customerMembershipId,
        public readonly string $serviceId,
        public readonly string $collaboratorId,
        public readonly DateTimeImmutable $startsAt,
        public readonly DateTimeImmutable $endsAt,
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly ?DateTimeImmutable $previousStartsAt,
        public readonly string $idempotencyKey,
        public readonly ?string $assistedClientId = null,
    ) {}

    /** Send the notice as its barbershop, in its transaction. */
    public function handle(BarbershopProcessScope $scope, SendNotice $send): void
    {
        $scope->run($this->barbershopId, fn (): bool => $send->execute(new NoticeCommand(
            $this->type,
            $this->customerMembershipId,
            $this->serviceId,
            $this->collaboratorId,
            $this->startsAt,
            $this->endsAt,
            $this->expiresAt,
            $this->previousStartsAt,
            idempotencyKey: $this->idempotencyKey,
            assistedClientId: $this->assistedClientId,
        )));
    }
}
