<?php

declare(strict_types=1);

/*
 * One request to join the waitlist, over its own connection: it runs the use case inside the transaction of the barbershop, as the
 * controller does, and prints how it ended.
 * Arguments: gate, barbershop, user, service, date.
 */

use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Src\Waitlist\Entries\Application\DTOs\JoinWaitlistCommand;
use Src\Waitlist\Entries\Application\UseCases\JoinWaitlist;
use Src\Waitlist\Entries\Domain\Exceptions\WaitlistLimitReachedException;
use Tests\Support\Shared\FixedBarbershopContext;

[, $barbershopId, $userId, $serviceId, $date] = require __DIR__.'/boot.php';

app()->instance(BarbershopContext::class, new FixedBarbershopContext(new BarbershopId($barbershopId)));

try {
    app(BarbershopTransaction::class)->run($barbershopId, fn () => app(JoinWaitlist::class)->execute(
        new JoinWaitlistCommand($userId, $serviceId, null, $date),
        new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica')),
    ));
    $outcome = 'joined';
} catch (WaitlistLimitReachedException $exception) {
    $outcome = 'limit';
} catch (Throwable $exception) {
    $outcome = $exception::class.': '.$exception->getMessage();
}

echo json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR);
