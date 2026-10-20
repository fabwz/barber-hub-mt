<?php

declare(strict_types=1);

namespace Src\Catalog\Closures\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $barbershop_id
 * @property CarbonImmutable $closed_on
 * @property string|null $reason
 */
#[Table('closures')]
#[Fillable(['id', 'barbershop_id', 'closed_on', 'reason'])]
final class ClosureModel extends Model
{
    use HasUuids;

    /**
     * Cast the closure date to an immutable calendar date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'closed_on' => 'immutable_date',
        ];
    }
}
