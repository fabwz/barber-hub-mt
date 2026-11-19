<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The aggregate decides the instants of the row, so the model does not touch the timestamps and does not cast them: they are
 * written as text with their offset and read back as text, so the instant never depends on the time zone of the process.
 *
 * @property string $id
 * @property string $barbershop_id
 * @property string $customer_membership_id
 * @property string $service_id
 * @property string|null $collaborator_id
 * @property string $desired_date
 * @property string $status
 * @property string $joined_at
 * @property string $created_at
 * @property string $updated_at
 */
#[Table('waitlist_entries')]
#[Fillable([
    'id',
    'barbershop_id',
    'customer_membership_id',
    'service_id',
    'collaborator_id',
    'desired_date',
    'status',
    'joined_at',
    'created_at',
    'updated_at',
])]
final class WaitlistEntryModel extends Model
{
    use HasUuids;

    public $timestamps = false;
}
