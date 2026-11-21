<?php

declare(strict_types=1);

/*
 * One operation of a race between the cancellation of a reservation and the settlement of its payment, over its own connection, with the real
 * use cases, as the request and the notice of the gateway run them. Prints a JSON with what became of it.
 * Arguments: gate, "cancel" or "notice", barbershop, user, reservation, provider reference, event identifier, now.
 * "cancel" cancels the reservation as the customer; "notice" applies a notice that the gateway approved the payment.
 */

use Src\Payments\Payments\Application\Contracts\PaymentGatewayRegistry;
use Src\Payments\Payments\Application\DTOs\GatewayNotice;
use Src\Payments\Payments\Application\UseCases\ProcessGatewayNotice;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Reservations\Reservations\Application\UseCases\CancelReservation;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Tests\Support\Payments\ArrayPaymentGatewayRegistry;
use Tests\Support\Payments\SimulatedPaymentGateway;
use Tests\Support\Shared\FixedBarbershopContext;

[, $mode, $barbershopId, $userId, $reservationId, $reference, $eventId, $now] = require __DIR__.'/boot.php';

// The task that offers the freed space is left in the queue after the commit; this process has nothing to run it with.
config(['queue.default' => 'null']);

try {
    if ($mode === 'cancel') {
        app()->instance(BarbershopContext::class, new FixedBarbershopContext(new BarbershopId($barbershopId)));
        $cancelled = app(BarbershopTransaction::class)->run($barbershopId, fn () => app(CancelReservation::class)->execute(
            $userId,
            false,
            $reservationId,
            new DateTimeImmutable($now),
        ));
        $outcome = 'cancelled:'.$cancelled->consequence->deposit->value;
    } else {
        app()->instance(PaymentGatewayRegistry::class, new ArrayPaymentGatewayRegistry([
            'onvo' => new SimulatedPaymentGateway(PaymentProvider::Onvo, [PaymentMethod::Card]),
        ]));
        $outcome = app(ProcessGatewayNotice::class)->execute(
            new GatewayNotice(PaymentProvider::Onvo, $eventId, 'payment_intent.succeeded', $reference),
            new DateTimeImmutable($now),
        )->outcome;
    }
} catch (Throwable $exception) {
    $outcome = $exception::class.': '.$exception->getMessage();
}

echo json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR);
