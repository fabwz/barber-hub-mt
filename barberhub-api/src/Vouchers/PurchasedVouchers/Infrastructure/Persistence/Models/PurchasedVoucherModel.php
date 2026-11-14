<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The aggregate decides the instants of the voucher, so the model does not touch the timestamps and does not cast them: they
 * are written as text with their offset and read back as text, so the instant never depends on the time zone of the process.
 *
 * @property string $id
 * @property string $barbershop_id
 * @property string $customer_membership_id
 * @property string $voucher_definition_id
 * @property string $name
 * @property int $price
 * @property int $sessions_count
 * @property int $validity_days
 * @property string $status
 * @property string|null $activated_at
 * @property string|null $expires_at
 * @property int $remaining_sessions
 * @property string $created_at
 * @property string $updated_at
 */
#[Table('purchased_vouchers')]
#[Fillable([
    'id',
    'barbershop_id',
    'customer_membership_id',
    'voucher_definition_id',
    'name',
    'price',
    'sessions_count',
    'validity_days',
    'status',
    'activated_at',
    'expires_at',
    'remaining_sessions',
    'created_at',
    'updated_at',
])]
final class PurchasedVoucherModel extends Model
{
    use HasUuids;

    public $timestamps = false;

    /**
     * Cast the amounts to integers.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'sessions_count' => 'integer',
            'validity_days' => 'integer',
            'remaining_sessions' => 'integer',
        ];
    }
}
