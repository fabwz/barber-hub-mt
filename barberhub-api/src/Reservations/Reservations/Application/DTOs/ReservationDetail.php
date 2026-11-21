<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

final readonly class ReservationDetail
{
    /**
     * Carry a reservation with what its role may see besides it: the balance still to pay (the staff, PA-10, CO-04) and the
     * history of statuses with authors (the owner, RE-09), and, for the customer of a confirmed reservation, what cancelling now would do (RF-17). Whatever is null is not for that role.
     *
     * @param  list<ReservationHistoryEntry>|null  $history
     */
    public function __construct(
        public ReservationView $view,
        public ?int $pendingBalance,
        public ?array $history,
        public ?CancellationPreview $cancellationNow = null,
    ) {}
}
