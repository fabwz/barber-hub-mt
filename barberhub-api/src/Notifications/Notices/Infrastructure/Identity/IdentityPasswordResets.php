<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Identity;

use Src\Identity\Users\Application\UseCases\IssuePasswordReset;
use Src\Notifications\Notices\Application\Contracts\PasswordResets;
use Src\Notifications\Notices\Application\DTOs\PasswordResetLink;
use Src\Notifications\Notices\Application\DTOs\Recipient;

final readonly class IdentityPasswordResets implements PasswordResets
{
    /** Receive the Identity use case that creates the link of an account. */
    public function __construct(private IssuePasswordReset $issue) {}

    /** Ask Identity for the link of the email and keep only what the notice needs. */
    public function issueFor(string $email): ?PasswordResetLink
    {
        $issued = $this->issue->execute($email);

        return $issued === null ? null : new PasswordResetLink(new Recipient($issued->name, $issued->email), $issued->token, $issued->expiresAt);
    }
}
