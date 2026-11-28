<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $barbershop_id
 * @property int $amount
 * @property CarbonImmutable $due_on
 * @property string $status
 */
#[Table('rent_installments')]
#[Fillable(['id', 'barbershop_id', 'amount', 'due_on', 'status'])]
final class RentInstallmentModel extends Model
{
    use HasUuids;

    /**
     * Cast the due day to an immutable calendar date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_on' => 'immutable_date',
        ];
    }
}
