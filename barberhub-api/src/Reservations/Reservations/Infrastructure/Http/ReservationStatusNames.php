<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http;

use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;

final class ReservationStatusNames
{
    /**
     * The name of each status in the API, in Spanish, as the glossary lists them.
     *
     * @var array<string, ReservationStatus>
     */
    private const array BY_NAME = [
        'temporal' => ReservationStatus::Temporary,
        'confirmada' => ReservationStatus::Confirmed,
        'completada' => ReservationStatus::Completed,
        'ausente' => ReservationStatus::NoShow,
        'cancelada_por_cliente' => ReservationStatus::CancelledByCustomer,
        'cancelada_por_barberia' => ReservationStatus::CancelledByBarbershop,
        'expirada' => ReservationStatus::Expired,
    ];

    /** The name of the status in the API. */
    public static function of(ReservationStatus $status): string
    {
        return (string) array_search($status, self::BY_NAME, true);
    }

    /** The status an API name stands for; the name must be one of `names()`. */
    public static function status(string $name): ReservationStatus
    {
        return self::BY_NAME[$name];
    }

    /**
     * Every name of the API.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::BY_NAME);
    }
}
