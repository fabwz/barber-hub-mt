<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $barbershop_id
 * @property string $role
 * @property bool $is_active
 */
#[Table('memberships')]
#[Fillable(['id', 'user_id', 'barbershop_id', 'role', 'is_active'])]
final class MembershipModel extends Model
{
    use HasUuids;

    /**
     * Cast the columns that are not strings to native types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
