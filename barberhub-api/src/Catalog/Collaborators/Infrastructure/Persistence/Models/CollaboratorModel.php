<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $barbershop_id
 * @property string $user_id
 * @property bool $is_active
 */
#[Table('collaborators')]
#[Fillable(['id', 'barbershop_id', 'user_id', 'is_active'])]
final class CollaboratorModel extends Model
{
    use HasUuids;

    /**
     * Cast the active flag to a native boolean.
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
