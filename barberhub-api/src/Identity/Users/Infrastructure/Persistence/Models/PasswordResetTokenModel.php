<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Persistence\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $user_id
 * @property string $token_hash
 * @property CarbonInterface $expires_at
 */
#[Table('password_reset_tokens')]
final class PasswordResetTokenModel extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';
}
