<?php

declare(strict_types=1);

/*
 * One of the contenders for a space that was freed, over its own connection: either the release that offers it to the line or the customer who
 * books it. Both run the real use case inside the transaction of the barbershop, as the listener of the queue and the controller do, and print a
 * JSON with how they ended.
 * Arguments: gate, "release" or "book", barbershop, collaborator, start, end, now, and for "book" also the user and the service.
 */

use Src\Reservations\Reservations\Application\DTOs\CreateReservationCommand;
use Src\Reservations\Reservations\Application\UseCases\CreateReservation;
use Src\Reservations\Reservations\Domain\Exceptions\SlotUnavailableException;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Src\Waitlist\Offers\Application\UseCases\OfferReleasedSpace;
use Tests\Support\Shared\FixedBarbershopContext;

[, $role, $barbershopId, $collaboratorId, $startsAt, $endsAt, $now, $userId, $serviceId] = [...require __DIR__.'/boot.php', null, null];

app()->instance(BarbershopContext::class, new FixedBarbershopContext(new BarbershopId($barbershopId)));
// The tasks left in the queue after the commit have nothing to run them in this process.
config(['queue.default' => 'null']);

try {
    if ($role === 'release') {
        $offer = app(BarbershopTransaction::class)->run($barbershopId, fn () => app(OfferReleasedSpace::class)->execute(
            $collaboratorId,
            new DateTimeImmutable($startsAt),
            new DateTimeImmutable($endsAt),
            new DateTimeImmutable($now),
        ));
        $outcome = $offer === null ? 'none' : 'offered';
    } else {
        app(BarbershopTransaction::class)->run($barbershopId, fn () => app(CreateReservation::class)->execute(
            new CreateReservationCommand((string) $userId, (string) $serviceId, $collaboratorId, new DateTimeImmutable($startsAt)),
            new DateTimeImmutable($now),
        ));
        $outcome = 'confirmed';
    }
} catch (SlotUnavailableException) {
    $outcome = 'espacio-no-disponible';
} catch (Throwable $exception) {
    $outcome = $exception::class.': '.$exception->getMessage();
}

echo json_encode(['role' => $role, 'outcome' => $outcome], JSON_THROW_ON_ERROR);
