<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $barbershop_id
 * @property string $collaborator_id
 * @property string $service_id
 * @property string $customer_membership_id
 * @property string $status
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable $blocked_until
 * @property int $price
 * @property int $duration_minutes
 * @property int $cancellation_deadline_hours
 * @property int $reschedule_count
 * @property CarbonImmutable|null $expires_at
 */
#[Table('reservations')]
#[Fillable([
    'id',
    'barbershop_id',
    'collaborator_id',
    'service_id',
    'customer_membership_id',
    'status',
    'starts_at',
    'ends_at',
    'blocked_until',
    'price',
    'duration_minutes',
    'cancellation_deadline_hours',
    'reschedule_count',
    'expires_at',
])]
final class ReservationModel extends Model
{
    use HasUuids;

    /**
     * Cast the instants to immutable dates and the copies of the service to integers.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'blocked_until' => 'immutable_datetime',
            'price' => 'integer',
            'duration_minutes' => 'integer',
            'cancellation_deadline_hours' => 'integer',
            'reschedule_count' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
