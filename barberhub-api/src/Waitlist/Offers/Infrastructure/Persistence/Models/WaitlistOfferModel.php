<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Persistence\Models;

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
 * @property string $waitlist_entry_id
 * @property string $collaborator_id
 * @property string $starts_at
 * @property string $ends_at
 * @property string $expires_at
 * @property string $status
 * @property string|null $responded_at
 * @property string $created_at
 * @property string|null $reservation_id
 */
#[Table('waitlist_offers')]
#[Fillable([
    'id',
    'barbershop_id',
    'waitlist_entry_id',
    'collaborator_id',
    'starts_at',
    'ends_at',
    'expires_at',
    'status',
    'responded_at',
    'created_at',
    'reservation_id',
])]
final class WaitlistOfferModel extends Model
{
    use HasUuids;

    public $timestamps = false;
}
