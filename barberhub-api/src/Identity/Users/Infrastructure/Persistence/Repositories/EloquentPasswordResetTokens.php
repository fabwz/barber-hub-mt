<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Persistence\Repositories;

use DateTimeImmutable;
use Src\Identity\Users\Domain\Contracts\PasswordResetTokens;
use Src\Identity\Users\Infrastructure\Persistence\Models\PasswordResetTokenModel;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class EloquentPasswordResetTokens implements PasswordResetTokens
{
    private const string INSTANT = 'Y-m-d H:i:s.uP';

    /** Keep the hash of the new link in the row of the account, over the one it had. */
    public function replace(UserId $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
        PasswordResetTokenModel::query()->upsert(
            [[
                'user_id' => $userId->value,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt->format(self::INSTANT),
                'created_at' => now()->format(self::INSTANT),
            ]],
            ['user_id'],
            ['token_hash', 'expires_at', 'created_at'],
        );
    }

    /**
     * Delete the row of a live link and answer its account in one statement, so a second request with the same link finds nothing.
     * The hash read back is compared with `hash_equals`, whatever the database did to find it.
     */
    public function consume(string $tokenHash, DateTimeImmutable $now): ?UserId
    {
        $rows = (new PasswordResetTokenModel)->getConnection()->select(
            'DELETE FROM password_reset_tokens WHERE token_hash = ? AND expires_at > ? RETURNING user_id, token_hash',
            [$tokenHash, $now->format(self::INSTANT)],
        );

        $row = $rows[0] ?? null;

        if ($row === null || ! hash_equals((string) $row->token_hash, $tokenHash)) {
            return null;
        }

        return new UserId((string) $row->user_id);
    }
}
