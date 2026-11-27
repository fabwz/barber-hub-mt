<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $barbershop_id
 * @property string $collaborator_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string|null $reason
 */
#[Table('collaborator_absences')]
#[Fillable(['id', 'barbershop_id', 'collaborator_id', 'starts_at', 'ends_at', 'reason'])]
final class AbsenceModel extends Model
{
    use HasUuids;

    /**
     * Cast the instants of the absence to immutable dates.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }
}
