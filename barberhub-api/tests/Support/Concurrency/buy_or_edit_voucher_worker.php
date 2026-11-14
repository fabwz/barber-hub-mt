<?php

declare(strict_types=1);

/*
 * One request over its own connection that either buys a voucher or edits its sessions, in the transaction of the barbershop as
 * the routes run them, so the purchase of a voucher (#88) and its edition by the owner (#87) can be raced. The gateway is simulated
 * and leaves the payment pending. A lock timeout above zero makes a request that has to wait for the row of the voucher say
 * "blocked" instead of waiting. It also prints the tables its queries touched before it ended, so a test can tell a request that waited
 * for the row before reading anything from one that read first and waited when it wrote.
 * Arguments: gate, barbershop, voucher definition, mode ("buy" or "edit"), account, lock timeout in milliseconds, now.
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Src\Payments\Payments\Application\Contracts\PaymentGatewayRegistry;
use Src\Payments\Payments\Application\DTOs\PayVoucherCommand;
use Src\Payments\Payments\Application\UseCases\PayVoucher;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Src\Vouchers\VoucherDefinitions\Application\DTOs\UpdateVoucherDefinitionCommand;
use Src\Vouchers\VoucherDefinitions\Application\UseCases\UpdateVoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Domain\Exceptions\VoucherDefinitionHasSalesException;
use Tests\Support\Payments\ArrayPaymentGatewayRegistry;
use Tests\Support\Payments\SimulatedPaymentGateway;
use Tests\Support\Shared\FixedBarbershopContext;

[, $barbershopId, $definitionId, $mode, $accountId, $lockTimeout, $now] = require __DIR__.'/boot.php';

app()->instance(BarbershopContext::class, new FixedBarbershopContext(new BarbershopId($barbershopId)));
app()->instance(PaymentGatewayRegistry::class, new ArrayPaymentGatewayRegistry([
    'onvo' => new SimulatedPaymentGateway(PaymentProvider::Onvo, [PaymentMethod::Card])->leavesPending(),
]));

DB::enableQueryLog();

try {
    app(BarbershopTransaction::class)->run($barbershopId, function () use ($mode, $definitionId, $accountId, $lockTimeout, $now): void {
        if ((int) $lockTimeout > 0) {
            DB::statement('set local lock_timeout = '.((int) $lockTimeout));
        }

        if ($mode === 'buy') {
            app(PayVoucher::class)->execute(new PayVoucherCommand($accountId, $definitionId, PaymentMethod::Card, 'pm_test_1'), new DateTimeImmutable($now));

            return;
        }

        app(UpdateVoucherDefinition::class)->execute(new UpdateVoucherDefinitionCommand($definitionId, sessionsCount: 8));
    });
    $outcome = $mode === 'buy' ? 'bought' : 'edited';
} catch (VoucherDefinitionHasSalesException $exception) {
    $outcome = $exception->rule();
} catch (QueryException $exception) {
    // 55P03: the row was locked by another transaction and the request would have waited.
    $outcome = $exception->getCode() === '55P03' ? 'blocked' : $exception::class.': '.$exception->getMessage();
} catch (Throwable $exception) {
    $outcome = $exception::class.': '.$exception->getMessage();
}

$tables = [];
foreach (DB::getQueryLog() as $query) {
    if (preg_match_all('/(?:from|into|update)\s+"?(\w+)"?/i', (string) $query['query'], $found) > 0) {
        array_push($tables, ...$found[1]);
    }
}

echo json_encode(['outcome' => $outcome, 'tables' => array_values(array_unique($tables))], JSON_THROW_ON_ERROR);
