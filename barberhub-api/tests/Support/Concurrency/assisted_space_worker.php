<?php

declare(strict_types=1);

/*
 * One contender of a race for a space between the owner who registers an assisted reservation and customers who book online, over its own connection:
 * it runs the real use case inside the transaction of the barbershop and prints a JSON with how it ended and how many times the exclusion of the
 * database had to refuse the reservation.
 * Arguments: gate, barbershop, "assisted" or "online", user (the owner or the customer), label (the name of the new assisted client), service, collaborator, start, now.
 */

use Src\Reservations\Reservations\Application\DTOs\CreateReservationCommand;
use Src\Reservations\Reservations\Application\DTOs\RegisterAssistedReservationCommand;
use Src\Reservations\Reservations\Application\UseCases\CreateReservation;
use Src\Reservations\Reservations\Application\UseCases\RegisterAssistedReservation;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Exceptions\SlotUnavailableException;
use Src\Reservations\Reservations\Infrastructure\Persistence\Repositories\EloquentReservationRepository;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Tests\Support\Reservations\CollisionCountingReservationRepository;
use Tests\Support\Shared\FixedBarbershopContext;

[, $barbershopId, $kind, $userId, $label, $serviceId, $collaboratorId, $startsAt, $now] = require __DIR__.'/boot.php';

app()->instance(BarbershopContext::class, new FixedBarbershopContext(new BarbershopId($barbershopId)));
// The tasks left in the queue after the commit have nothing to run them in this process.
config(['queue.default' => 'null']);

$repository = new CollisionCountingReservationRepository(app(EloquentReservationRepository::class));
app()->instance(ReservationRepository::class, $repository);

try {
    app(BarbershopTransaction::class)->run($barbershopId, fn () => $kind === 'assisted'
        ? app(RegisterAssistedReservation::class)->execute(
            new RegisterAssistedReservationCommand($userId, $serviceId, $collaboratorId, new DateTimeImmutable($startsAt), null, $label, '8000 0000', "{$label}@correo.cr"),
            new DateTimeImmutable($now),
        )
        : app(CreateReservation::class)->execute(
            new CreateReservationCommand($userId, $serviceId, $collaboratorId, new DateTimeImmutable($startsAt)),
            new DateTimeImmutable($now),
        ));
    $outcome = 'confirmed';
} catch (SlotUnavailableException) {
    $outcome = 'espacio-no-disponible';
} catch (Throwable $exception) {
    $outcome = $exception::class.': '.$exception->getMessage();
}

echo json_encode(['kind' => $kind, 'outcome' => $outcome, 'collisions' => $repository->collisions], JSON_THROW_ON_ERROR);
