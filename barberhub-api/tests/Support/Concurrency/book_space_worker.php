<?php

declare(strict_types=1);

/*
 * One customer of a race for a space: books it with the real use case, over its own connection, and prints a JSON with
 * the outcome and how many times the exclusion of the database had to refuse the reservation.
 * Arguments: gate, barbershop, user, service, collaborator, start, now, "lock" or "no-lock".
 * With "no-lock" the collaborator lock does nothing, so the test can show that it is what keeps the requests apart.
 */

use Src\Reservations\Reservations\Application\Contracts\CollaboratorLock;
use Src\Reservations\Reservations\Application\DTOs\CreateReservationCommand;
use Src\Reservations\Reservations\Application\UseCases\CreateReservation;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Exceptions\SlotUnavailableException;
use Src\Reservations\Reservations\Infrastructure\Persistence\Repositories\EloquentReservationRepository;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Tests\Support\Reservations\CollisionCountingReservationRepository;
use Tests\Support\Reservations\NoCollaboratorLock;
use Tests\Support\Shared\FixedBarbershopContext;

[, $barbershopId, $userId, $serviceId, $collaboratorId, $startsAt, $now, $mode] = require __DIR__.'/boot.php';

app()->instance(BarbershopContext::class, new FixedBarbershopContext(new BarbershopId($barbershopId)));

if ($mode === 'no-lock') {
    app()->bind(CollaboratorLock::class, NoCollaboratorLock::class);
}

$repository = new CollisionCountingReservationRepository(app(EloquentReservationRepository::class));
app()->instance(ReservationRepository::class, $repository);

try {
    app(BarbershopTransaction::class)->run($barbershopId, fn () => app(CreateReservation::class)->execute(
        new CreateReservationCommand($userId, $serviceId, $collaboratorId, new DateTimeImmutable($startsAt)),
        new DateTimeImmutable($now),
    ));
    $outcome = 'confirmed';
} catch (SlotUnavailableException) {
    $outcome = 'espacio-no-disponible';
} catch (Throwable $exception) {
    $outcome = $exception::class.': '.$exception->getMessage();
}

echo json_encode(['outcome' => $outcome, 'collisions' => $repository->collisions], JSON_THROW_ON_ERROR);
