<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An entry of the history is only inserted, never updated, so the table has no update timestamp.
 *
 * @property string $id
 * @property string $barbershop_id
 * @property string $reservation_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $changed_by_user_id
 * @property CarbonImmutable $changed_at
 */
#[Table('reservation_status_history')]
#[Fillable(['id', 'barbershop_id', 'reservation_id', 'from_status', 'to_status', 'changed_by_user_id', 'changed_at'])]
final class ReservationStatusChangeModel extends Model
{
    use HasUuids;

    public $timestamps = false;

    /**
     * Cast the moment of the change to an immutable date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changed_at' => 'immutable_datetime',
        ];
    }
}
