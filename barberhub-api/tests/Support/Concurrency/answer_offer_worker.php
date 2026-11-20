<?php

declare(strict_types=1);

/*
 * One contender for an offer, over its own connection: the customer who accepts it, the one who turns it down or the task of the queue that
 * expires it. Each runs the real use case inside the transaction of the barbershop, as the controller and the job do, and prints a JSON with how
 * it ended.
 * Arguments: gate, "accept", "reject" or "expire", barbershop, offer, user, now.
 */

use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Src\Waitlist\Offers\Application\UseCases\AcceptOffer;
use Src\Waitlist\Offers\Application\UseCases\ExpireOffer;
use Src\Waitlist\Offers\Application\UseCases\RejectOffer;
use Src\Waitlist\Offers\Domain\Exceptions\OfferAlreadyAnsweredException;
use Src\Waitlist\Offers\Domain\Exceptions\OfferExpiredException;
use Tests\Support\Shared\FixedBarbershopContext;

[, $role, $barbershopId, $offerId, $userId, $now] = require __DIR__.'/boot.php';

app()->instance(BarbershopContext::class, new FixedBarbershopContext(new BarbershopId($barbershopId)));
// The tasks left in the queue after the commit have nothing to run them in this process.
config(['queue.default' => 'null']);

$at = new DateTimeImmutable($now);

try {
    $reservation = null;

    if ($role === 'accept') {
        $view = app(BarbershopTransaction::class)->run($barbershopId, fn () => app(AcceptOffer::class)->execute($userId, $offerId, false, $at));
        $outcome = 'ok';
        $reservation = $view->offer->reservationId?->value;
    } elseif ($role === 'reject') {
        app(BarbershopTransaction::class)->run($barbershopId, fn () => app(RejectOffer::class)->execute($userId, $offerId, $at));
        $outcome = 'ok';
    } else {
        $expired = app(BarbershopTransaction::class)->run($barbershopId, fn () => app(ExpireOffer::class)->execute($offerId, $at));
        $outcome = $expired ? 'expired' : 'unchanged';
    }
} catch (OfferExpiredException) {
    $outcome = 'oferta-vencida';
} catch (OfferAlreadyAnsweredException) {
    $outcome = 'conflicto';
} catch (Throwable $exception) {
    $outcome = $exception::class.': '.$exception->getMessage();
}

echo json_encode(['role' => $role, 'outcome' => $outcome, 'reservation' => $reservation ?? null], JSON_THROW_ON_ERROR);
