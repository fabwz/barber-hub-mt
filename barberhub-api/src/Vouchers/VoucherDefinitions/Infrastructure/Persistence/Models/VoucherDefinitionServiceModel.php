<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A service a voucher covers.
 *
 * @property string $id
 * @property string $barbershop_id
 * @property string $voucher_definition_id
 * @property string $service_id
 */
#[Table('voucher_definition_services')]
#[Fillable(['id', 'barbershop_id', 'voucher_definition_id', 'service_id'])]
final class VoucherDefinitionServiceModel extends Model
{
    use HasUuids;

    public $timestamps = false;
}
