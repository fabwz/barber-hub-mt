<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An entry of the record of a balance. The table only accepts new rows: the application role has no UPDATE or DELETE on it.
 * The instant is written and read as text, like the one of the voucher.
 *
 * @property string $id
 * @property string $barbershop_id
 * @property string $purchased_voucher_id
 * @property string|null $reservation_id
 * @property string $type
 * @property int $sessions_delta
 * @property string $created_at
 */
#[Table('voucher_movements')]
#[Fillable([
    'id',
    'barbershop_id',
    'purchased_voucher_id',
    'reservation_id',
    'type',
    'sessions_delta',
    'created_at',
])]
final class VoucherMovementModel extends Model
{
    use HasUuids;

    public $timestamps = false;

    /**
     * Cast the amount to an integer.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sessions_delta' => 'integer'];
    }
}
