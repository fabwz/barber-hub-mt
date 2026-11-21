<?php

declare(strict_types=1);

/*
 * One reservation paid with a voucher, over its own connection: it runs the real use case of the booking (RF-28) inside the
 * transaction of the barbershop and prints a JSON with how it ended: "confirmed" or the stable type of the refusal.
 * Arguments: gate, barbershop, user, service, collaborator, start, now, "control" or "no-control".
 * With "no-control" the session is taken by reading the balance and writing it back, so the test can show that the conditional
 * update of the repository is what keeps the balance from being sold twice.
 */

use Src\Reservations\Reservations\Application\DTOs\CreateReservationCommand;
use Src\Reservations\Reservations\Application\UseCases\CreateReservation;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Exceptions\BusinessRuleException;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Vouchers\UncontrolledVoucherRepository;

[, $barbershopId, $userId, $serviceId, $collaboratorId, $startsAt, $now, $mode] = require __DIR__.'/boot.php';

app()->instance(BarbershopContext::class, new FixedBarbershopContext(new BarbershopId($barbershopId)));

if ($mode === 'no-control') {
    app()->instance(PurchasedVoucherRepository::class, new UncontrolledVoucherRepository(
        app(PurchasedVoucherRepository::class),
        app(BarbershopContext::class),
    ));
}

try {
    app(BarbershopTransaction::class)->run($barbershopId, fn () => app(CreateReservation::class)->execute(
        new CreateReservationCommand($userId, $serviceId, $collaboratorId, new DateTimeImmutable($startsAt), withVoucher: true),
        new DateTimeImmutable($now),
    ));
    $outcome = 'confirmed';
} catch (BusinessRuleException $exception) {
    $outcome = $exception->rule();
} catch (Throwable $exception) {
    $outcome = $exception::class.': '.$exception->getMessage();
}

echo json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR);
