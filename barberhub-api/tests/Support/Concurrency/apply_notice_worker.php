<?php

declare(strict_types=1);

/*
 * One delivery of a notice of a gateway, over its own connection: it runs the real use case that applies a verified notice (the
 * verification has its own tests), with the gateway simulated as approving, and prints what became of it.
 * Arguments: gate, provider reference, event identifier, now.
 */

use Src\Payments\Payments\Application\Contracts\PaymentGatewayRegistry;
use Src\Payments\Payments\Application\DTOs\GatewayNotice;
use Src\Payments\Payments\Application\UseCases\ProcessGatewayNotice;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Tests\Support\Payments\ArrayPaymentGatewayRegistry;
use Tests\Support\Payments\SimulatedPaymentGateway;

[, $reference, $eventId, $now] = require __DIR__.'/boot.php';

app()->instance(PaymentGatewayRegistry::class, new ArrayPaymentGatewayRegistry([
    'onvo' => new SimulatedPaymentGateway(PaymentProvider::Onvo, [PaymentMethod::Card]),
]));

try {
    $result = app(ProcessGatewayNotice::class)->execute(
        new GatewayNotice(PaymentProvider::Onvo, $eventId, 'payment_intent.succeeded', $reference),
        new DateTimeImmutable($now),
    );
    $outcome = $result->outcome;
} catch (Throwable $exception) {
    $outcome = $exception::class.': '.$exception->getMessage();
}

echo json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR);
