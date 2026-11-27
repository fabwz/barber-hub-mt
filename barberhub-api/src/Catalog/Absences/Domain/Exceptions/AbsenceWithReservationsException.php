<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Domain\Exceptions;

use Src\Catalog\Absences\Domain\ValueObjects\ConflictingReservation;
use Src\Shared\Domain\Exceptions\BusinessRuleException;
use Src\Shared\Domain\Exceptions\HasProblemMembers;

final class AbsenceWithReservationsException extends BusinessRuleException implements HasProblemMembers
{
    /** @var list<ConflictingReservation> */
    private array $reservations = [];

    private int $total = 0;

    /**
     * Build the exception for an absence that falls on reservations the owner has not resolved (CO-06). `$reservations` are the
     * earliest ones, as many as the caller chose to list, and `$total` how many there are in all.
     *
     * @param  list<ConflictingReservation>  $reservations
     */
    public static function with(array $reservations, int $total): self
    {
        $exception = new self('absences.has_reservations', ['total' => $total]);
        $exception->reservations = $reservations;
        $exception->total = $total;

        return $exception;
    }

    /**
     * The reservations that stop the absence from being saved, the earliest first.
     *
     * @return list<ConflictingReservation>
     */
    public function reservations(): array
    {
        return $this->reservations;
    }

    /** The stable type of this error: the absence is not saved while it falls on reservations. */
    public function rule(): string
    {
        return 'ausencia-con-reservas';
    }

    /**
     * The reservations as the API publishes them: `reservas` with the identifier, the start and the end in ISO 8601 with the
     * offset of Costa Rica, the service, the customer and the status of each, and `total_reservas` with how many there are in all.
     *
     * @return array{reservas: list<array{id: string, inicio: string, fin: string, servicio: string, cliente: string, estado: string}>, total_reservas: int}
     */
    public function problemMembers(): array
    {
        return [
            'reservas' => array_map(fn (ConflictingReservation $reservation): array => [
                'id' => $reservation->reservationId,
                'inicio' => $reservation->startsAt->format(DATE_ATOM),
                'fin' => $reservation->endsAt->format(DATE_ATOM),
                'servicio' => $reservation->serviceName,
                'cliente' => $reservation->customerName,
                'estado' => $reservation->status,
            ], $this->reservations),
            'total_reservas' => $this->total,
        ];
    }
}
