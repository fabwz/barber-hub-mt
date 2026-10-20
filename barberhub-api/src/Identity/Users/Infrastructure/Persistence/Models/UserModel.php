<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Persistence\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $phone
 * @property bool $is_active
 * @property bool $must_change_password
 * @property CarbonInterface|null $terms_accepted_at
 * @property CarbonInterface|null $privacy_accepted_at
 */
#[Table('users')]
#[Fillable(['id', 'name', 'email', 'password', 'phone', 'is_active', 'must_change_password', 'terms_accepted_at', 'privacy_accepted_at'])]
#[Hidden(['password'])]
final class UserModel extends Authenticatable
{
    use HasApiTokens, HasUuids;

    /**
     * Cast the columns that are not strings to native types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
        ];
    }
}
