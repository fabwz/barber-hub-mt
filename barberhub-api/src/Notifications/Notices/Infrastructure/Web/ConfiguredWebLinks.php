<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Web;

use SensitiveParameter;
use Src\Notifications\Notices\Application\Contracts\WebLinks;

final readonly class ConfiguredWebLinks implements WebLinks
{
    /** Receive the address of the web client, from the configuration (`notices.web_url`). */
    public function __construct(private string $baseUrl) {}

    /** The screen «Crear nueva contraseña» of the web client, with the token in the query. */
    public function passwordReset(#[SensitiveParameter] string $token): string
    {
        return rtrim($this->baseUrl, '/').'/restablecer-contrasena?'.http_build_query(['token' => $token]);
    }

    /** The screen «Aceptar invitación» of the web client, with the token in the query. */
    public function invitationAcceptance(#[SensitiveParameter] string $token): string
    {
        return rtrim($this->baseUrl, '/').'/aceptar-invitacion?'.http_build_query(['token' => $token]);
    }
}
