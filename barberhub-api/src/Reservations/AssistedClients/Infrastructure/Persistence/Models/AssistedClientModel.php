<?php

declare(strict_types=1);

namespace Src\Reservations\AssistedClients\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $barbershop_id
 * @property string $name
 * @property string $phone
 * @property string|null $email
 */
#[Table('assisted_clients')]
#[Fillable(['id', 'barbershop_id', 'name', 'phone', 'email'])]
final class AssistedClientModel extends Model
{
    use HasUuids;
}
