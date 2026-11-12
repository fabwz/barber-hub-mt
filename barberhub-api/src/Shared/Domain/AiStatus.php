<?php

declare(strict_types=1);

namespace Src\Shared\Domain;

/** State of the language provider as the health route reports it: information only, it never changes the general state. */
enum AiStatus: string
{
    case Available = 'disponible';
    case Degraded = 'degradada';
    case NotConfigured = 'no_configurada';
}
