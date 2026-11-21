<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http;

use Src\Reservations\Reservations\Domain\ValueObjects\CancellationClass;
use Src\Reservations\Reservations\Domain\ValueObjects\DepositOutcome;
use Src\Reservations\Reservations\Domain\ValueObjects\SessionOutcome;

/** The names the API gives, in Spanish, to the class of a cancellation and to what happens to the deposit and to the session (RD-07). */
final class ConsequenceNames
{
    /** The class of a cancellation: `a_tiempo`, `tardia` or `por_la_barberia`. */
    public static function cancellation(CancellationClass $class): string
    {
        return match ($class) {
            CancellationClass::OnTime => 'a_tiempo',
            CancellationClass::Late => 'tardia',
            CancellationClass::ByBarbershop => 'por_la_barberia',
        };
    }

    /** What happens to the deposit: `a_devolver`, `retenido` or `ninguno`. */
    public static function deposit(DepositOutcome $deposit): string
    {
        return match ($deposit) {
            DepositOutcome::ToRefund => 'a_devolver',
            DepositOutcome::Retained => 'retenido',
            DepositOutcome::None => 'ninguno',
        };
    }

    /** What happens to the session of the voucher: `se_reintegra`, `se_consume` or `ninguna`. */
    public static function session(SessionOutcome $session): string
    {
        return match ($session) {
            SessionOutcome::Released => 'se_reintegra',
            SessionOutcome::Consumed => 'se_consume',
            SessionOutcome::None => 'ninguna',
        };
    }
}
