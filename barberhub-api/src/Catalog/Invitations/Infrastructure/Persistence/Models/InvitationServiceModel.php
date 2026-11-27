<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $barbershop_id
 * @property string $invitation_id
 * @property string $service_id
 */
#[Table('invitation_services')]
final class InvitationServiceModel extends Model
{
    use HasUuids;

    public $timestamps = false;
}
