<?php

declare(strict_types=1);

/*
 * One release of a space, over its own connection: it runs the use case that offers a freed space to the line, inside the transaction of the
 * barbershop, as the listener of the queue does, and prints a JSON with the offer it made, if any.
 * Arguments: gate, barbershop, collaborator, start, end, now.
 */

use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Src\Waitlist\Offers\Application\UseCases\OfferReleasedSpace;
use Tests\Support\Shared\FixedBarbershopContext;

[, $barbershopId, $collaboratorId, $startsAt, $endsAt, $now] = require __DIR__.'/boot.php';

app()->instance(BarbershopContext::class, new FixedBarbershopContext(new BarbershopId($barbershopId)));
// The task that expires the offer is left in the queue after the commit; this process has nothing to run it with.
config(['queue.default' => 'null']);

try {
    $offer = app(BarbershopTransaction::class)->run($barbershopId, fn () => app(OfferReleasedSpace::class)->execute(
        $collaboratorId,
        new DateTimeImmutable($startsAt),
        new DateTimeImmutable($endsAt),
        new DateTimeImmutable($now),
    ));
    $outcome = $offer === null ? 'none' : 'offered';
    $entry = $offer?->entryId->value;
} catch (Throwable $exception) {
    $outcome = $exception::class.': '.$exception->getMessage();
    $entry = null;
}

echo json_encode(['outcome' => $outcome, 'entry' => $entry], JSON_THROW_ON_ERROR);
