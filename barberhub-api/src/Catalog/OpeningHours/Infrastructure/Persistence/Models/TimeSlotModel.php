<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $barbershop_id
 * @property int $day_of_week
 * @property string $opens_at
 * @property string $closes_at
 */
#[Table('opening_hours')]
#[Fillable(['id', 'barbershop_id', 'day_of_week', 'opens_at', 'closes_at'])]
final class TimeSlotModel extends Model
{
    use HasUuids;

    /**
     * Cast the day column to a native integer.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }
}
