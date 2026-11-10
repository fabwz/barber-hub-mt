<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Notices;

use Illuminate\Support\Facades\DB;
use Src\Payments\Payments\Application\Contracts\PaymentLocator;
use Src\Payments\Payments\Application\DTOs\PaymentLocation;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;

/**
 * Finds the payment of a notice through the function `locate_payment_of_notice` of the database, the only thing that can read
 * across barbershops: read-only, fixed search_path, answering just the two identifiers. It changes no policy and the request
 * keeps the application role (docs/database.md).
 */
final readonly class DatabasePaymentLocator implements PaymentLocator
{
    public function locate(PaymentProvider $provider, string $reference): ?PaymentLocation
    {
        // Inside a transaction, so the barbershop the function sets while it looks never outlives the call.
        /** @var object{barbershop_id: string, payment_id: string}|null $row */
        $row = DB::transaction(fn (): ?object => DB::selectOne('select barbershop_id, payment_id from locate_payment_of_notice(?, ?)', [$provider->value, $reference]));

        return $row === null ? null : new PaymentLocation($row->barbershop_id, $row->payment_id);
    }
}
