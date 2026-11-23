<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\Exceptions;

use Src\Catalog\Collaborators\Domain\ValueObjects\UpcomingReservation;
use Src\Shared\Domain\Exceptions\BusinessRuleException;
use Src\Shared\Domain\Exceptions\HasProblemMembers;

final class CollaboratorHasUpcomingReservationsException extends BusinessRuleException implements HasProblemMembers
{
    /** @var list<UpcomingReservation> */
    private array $reservations = [];

    private int $total = 0;

    /**
     * Build the exception for a collaborator with confirmed reservations ahead (CO-03). `$reservations` are the nearest ones,
     * as many as the caller chose to list, and `$total` how many there are in all.
     *
     * @param  list<UpcomingReservation>  $reservations
     */
    public static function with(array $reservations, int $total): self
    {
        $exception = new self('collaborators.has_upcoming_reservations', ['total' => $total]);
        $exception->reservations = $reservations;
        $exception->total = $total;

        return $exception;
    }

    /**
     * The reservations that block the deactivation, the nearest first.
     *
     * @return list<UpcomingReservation>
     */
    public function reservations(): array
    {
        return $this->reservations;
    }

    /** The stable type of this error: the collaborator cannot be deactivated while they have reservations to attend. */
    public function rule(): string
    {
        return 'colaborador-con-reservas';
    }

    /**
     * The reservations as the API publishes them: `reservas` with the identifier, the start in ISO 8601, the service and the
     * customer of each, and `total_reservas` with how many there are in all (the list may be shorter).
     *
     * @return array{reservas: list<array{id: string, inicio: string, servicio: string, cliente: string}>, total_reservas: int}
     */
    public function problemMembers(): array
    {
        return [
            'reservas' => array_map(fn (UpcomingReservation $reservation): array => [
                'id' => $reservation->reservationId,
                'inicio' => $reservation->startsAt->format(DATE_ATOM),
                'servicio' => $reservation->serviceName,
                'cliente' => $reservation->customerName,
            ], $this->reservations),
            'total_reservas' => $this->total,
        ];
    }
}
