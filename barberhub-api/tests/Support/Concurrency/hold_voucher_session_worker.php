<?php

declare(strict_types=1);

/*
 * One request for a session of a voucher, over its own connection: it runs the atomic operation of the repository inside the
 * transaction of the barbershop, as the use case of the reservation will, and prints how it ended.
 * Arguments: gate, barbershop, voucher, reservation, now.
 */

use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\InsufficientBalanceException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\PurchasedVoucherId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\ReservationId;
use Tests\Support\Shared\FixedBarbershopContext;

[, $barbershopId, $voucherId, $reservationId, $now] = require __DIR__.'/boot.php';

app()->instance(BarbershopContext::class, new FixedBarbershopContext(new BarbershopId($barbershopId)));

try {
    app(BarbershopTransaction::class)->run($barbershopId, fn () => app(PurchasedVoucherRepository::class)->holdSession(
        new PurchasedVoucherId($voucherId),
        new ReservationId($reservationId),
        new DateTimeImmutable($now),
    ));
    $outcome = 'confirmed';
} catch (InsufficientBalanceException $exception) {
    $outcome = $exception->rule();
} catch (Throwable $exception) {
    $outcome = $exception::class.': '.$exception->getMessage();
}

echo json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR);
