<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The aggregate decides the instants, so the model does not touch the timestamps and does not cast them: they are written as
 * text with their offset and read back as text.
 *
 * @property string $id
 * @property string $barbershop_id
 * @property string $name
 * @property int $sessions_count
 * @property int $price
 * @property int $validity_days
 * @property bool $is_active
 * @property string $created_at
 * @property string $updated_at
 */
#[Table('voucher_definitions')]
#[Fillable([
    'id',
    'barbershop_id',
    'name',
    'sessions_count',
    'price',
    'validity_days',
    'is_active',
    'created_at',
    'updated_at',
])]
final class VoucherDefinitionModel extends Model
{
    use HasUuids;

    public $timestamps = false;

    /**
     * Cast the amounts to integers and the flag to a boolean.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sessions_count' => 'integer',
            'price' => 'integer',
            'validity_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
