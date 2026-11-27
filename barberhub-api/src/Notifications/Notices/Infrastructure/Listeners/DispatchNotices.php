<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Listeners;

use Src\Notifications\Notices\Application\DTOs\NoticeType;
use Src\Notifications\Notices\Infrastructure\Jobs\SendNoticeJob;
use Src\Reservations\Reservations\Domain\Events\ReservationCancelled;
use Src\Reservations\Reservations\Domain\Events\ReservationConfirmed;
use Src\Reservations\Reservations\Domain\Events\ReservationRescheduled;
use Src\Waitlist\Offers\Domain\Events\OfferCreated;

/**
 * Turns the events that the customer must hear about into a task of the queue (RF-34, NO-01, NO-07). It does no work of its own: it only
 * leaves the task, which waits for the commit of the transaction that published the event, so nothing it does can undo a reservation. The
 * cancellation and the reschedule are told only when the barbershop made them; the customer who does it already knows (NO-07).
 */
final class DispatchNotices
{
    /** A reservation was confirmed, by whichever way (RE-07, payment, voucher, accepted offer). */
    public function onConfirmed(ReservationConfirmed $event): void
    {
        SendNoticeJob::dispatch(
            $event->barbershopId,
            NoticeType::Confirmation,
            $event->customerMembershipId,
            $event->serviceId,
            $event->collaboratorId,
            $event->startsAt,
            $event->endsAt,
            null,
            null,
            "confirmation-{$event->reservationId}",
            $event->assistedClientId,
        );
    }

    /** A space was offered to a person of the waitlist. */
    public function onOffered(OfferCreated $event): void
    {
        SendNoticeJob::dispatch(
            $event->barbershopId,
            NoticeType::Offer,
            $event->customerMembershipId,
            $event->serviceId,
            $event->collaboratorId,
            $event->startsAt,
            $event->endsAt,
            $event->expiresAt,
            null,
            "offer-{$event->offerId}",
        );
    }

    /** A reservation was cancelled; only the cancellation of the barbershop is told. */
    public function onCancelled(ReservationCancelled $event): void
    {
        if (! $event->byBarbershop) {
            return;
        }

        SendNoticeJob::dispatch(
            $event->barbershopId,
            NoticeType::Cancellation,
            $event->customerMembershipId,
            $event->serviceId,
            $event->collaboratorId,
            $event->startsAt,
            $event->endsAt,
            null,
            null,
            "cancellation-{$event->reservationId}",
            $event->assistedClientId,
        );
    }

    /** A reservation was moved; only the change that the barbershop made is told. */
    public function onRescheduled(ReservationRescheduled $event): void
    {
        if (! $event->byBarbershop) {
            return;
        }

        SendNoticeJob::dispatch(
            $event->barbershopId,
            NoticeType::Reschedule,
            $event->customerMembershipId,
            $event->serviceId,
            $event->collaboratorId,
            $event->startsAt,
            $event->endsAt,
            null,
            $event->previousStartsAt,
            "reschedule-{$event->reservationId}-{$event->startsAt->getTimestamp()}",
            $event->assistedClientId,
        );
    }
}
