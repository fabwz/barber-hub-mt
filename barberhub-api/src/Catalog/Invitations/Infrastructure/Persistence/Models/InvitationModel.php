<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $barbershop_id
 * @property string $email
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property string $invited_by_membership_id
 * @property CarbonImmutable $created_at
 */
#[Table('invitations')]
final class InvitationModel extends Model
{
    use HasUuids;

    public $timestamps = false;

    /**
     * Cast the instants to immutable dates.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
