<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The aggregate decides the instants of the payment, so the model does not touch the timestamps.
 *
 * @property string $id
 * @property string $barbershop_id
 * @property string $reservation_id
 * @property string $provider
 * @property string $method
 * @property string $status
 * @property int $amount
 * @property string|null $provider_currency
 * @property int|null $provider_amount
 * @property int|null $provider_exchange_rate
 * @property string|null $provider_capture_id
 * @property string|null $provider_payment_id
 * @property string|null $failure_reason
 * @property bool $refund_pending
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Table('payments')]
#[Fillable([
    'id',
    'barbershop_id',
    'reservation_id',
    'provider',
    'method',
    'status',
    'amount',
    'provider_currency',
    'provider_amount',
    'provider_exchange_rate',
    'provider_payment_id',
    'provider_capture_id',
    'failure_reason',
    'refund_pending',
    'created_at',
    'updated_at',
])]
final class PaymentModel extends Model
{
    use HasUuids;

    public $timestamps = false;

    /**
     * Cast the amounts to integers and the instants to immutable dates.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'provider_amount' => 'integer',
            'provider_exchange_rate' => 'integer',
            'refund_pending' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
