<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The rows of the table are written by the repository with statements that decide the instants, so the model only names the table.
 *
 * @property string $id
 * @property string $barbershop_id
 * @property string $reservation_id
 * @property string $kind
 * @property string $scheduled_for
 * @property string|null $sent_at
 */
#[Table('reservation_reminders')]
final class ReservationReminderModel extends Model
{
    use HasUuids;

    public $timestamps = false;
}
