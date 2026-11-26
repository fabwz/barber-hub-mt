<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use DateTimeImmutable;
use Src\Identity\Users\Domain\Contracts\PasswordResetTokens;
use Src\Shared\Domain\ValueObjects\UserId;

/** The links to reset a password in memory, with the rules of the real store: one per account, used once, valid until its instant. */
final class InMemoryPasswordResetTokens implements PasswordResetTokens
{
    /** @var array<string, array{hash: string, expiresAt: DateTimeImmutable}> keyed by the identifier of the account */
    private array $rows = [];

    /** Keep the hash for the account, over the one it had. */
    public function replace(UserId $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
        $this->rows[$userId->value] = ['hash' => $tokenHash, 'expiresAt' => $expiresAt];
    }

    /** Delete the live link with that hash and answer its account. */
    public function consume(string $tokenHash, DateTimeImmutable $now): ?UserId
    {
        foreach ($this->rows as $userId => $row) {
            if (hash_equals($row['hash'], $tokenHash) && $row['expiresAt'] > $now) {
                unset($this->rows[$userId]);

                return new UserId($userId);
            }
        }

        return null;
    }

    /**
     * Every hash stored, to prove that no plain token is.
     *
     * @return list<string>
     */
    public function storedHashes(): array
    {
        return array_values(array_map(fn (array $row): string => $row['hash'], $this->rows));
    }

    /** The instant the link of an account expires; null when it has none. */
    public function expiresAt(UserId $userId): ?DateTimeImmutable
    {
        return ($this->rows[$userId->value] ?? null)['expiresAt'] ?? null;
    }

    /** Count the links pending. */
    public function count(): int
    {
        return count($this->rows);
    }
}
