<?php

declare(strict_types=1);

/*
 * One contender of a race between the block of an agenda and the booking of a space inside it, over its own connection, with the real use
 * cases, as the requests run them. Prints a JSON with the outcome.
 * Arguments: gate, "book" or "block", barbershop, then for "book": user, service, collaborator, start, now;
 * for "block": collaborator, date, first hour, last hour, now.
 */

use Src\Catalog\Absences\Application\DTOs\RegisterAbsenceCommand;
use Src\Catalog\Absences\Application\UseCases\RegisterAbsence;
use Src\Catalog\Absences\Domain\Exceptions\AbsenceWithReservationsException;
use Src\Reservations\Reservations\Application\DTOs\CreateReservationCommand;
use Src\Reservations\Reservations\Application\UseCases\CreateReservation;
use Src\Reservations\Reservations\Domain\Exceptions\SlotUnavailableException;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Tests\Support\Shared\FixedBarbershopContext;

[, $mode, $barbershopId] = $arguments = require __DIR__.'/boot.php';

app()->instance(BarbershopContext::class, new FixedBarbershopContext(new BarbershopId($barbershopId)));

try {
    if ($mode === 'book') {
        [, , , $userId, $serviceId, $collaboratorId, $startsAt, $now] = $arguments;
        app(BarbershopTransaction::class)->run($barbershopId, fn () => app(CreateReservation::class)->execute(
            new CreateReservationCommand($userId, $serviceId, $collaboratorId, new DateTimeImmutable($startsAt)),
            new DateTimeImmutable($now),
        ));
        $outcome = 'booked';
    } else {
        [, , , $collaboratorId, $date, $from, $to, $now] = $arguments;
        app(BarbershopTransaction::class)->run($barbershopId, fn () => app(RegisterAbsence::class)->execute(
            new RegisterAbsenceCommand($collaboratorId, $date, $date, $from, $to, null),
            new DateTimeImmutable($now),
        ));
        $outcome = 'blocked';
    }
} catch (SlotUnavailableException) {
    $outcome = 'espacio-no-disponible';
} catch (AbsenceWithReservationsException) {
    $outcome = 'ausencia-con-reservas';
} catch (Throwable $exception) {
    $outcome = $exception::class.': '.$exception->getMessage();
}

echo json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR);
