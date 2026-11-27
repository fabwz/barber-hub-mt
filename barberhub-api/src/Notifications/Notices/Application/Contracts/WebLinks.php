<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\Contracts;

use SensitiveParameter;

/** The addresses of the web client that a notice points to. */
interface WebLinks
{
    /** The absolute address of the screen where the person sets a new password with the token of the link. */
    public function passwordReset(#[SensitiveParameter] string $token): string;

    /** The absolute address of the screen where an invited person creates their password with the token of the link (RF-06). */
    public function invitationAcceptance(#[SensitiveParameter] string $token): string;
}
