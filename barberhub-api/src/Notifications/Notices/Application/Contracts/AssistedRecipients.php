<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\Contracts;

use Src\Notifications\Notices\Application\DTOs\Recipient;

/** Who an assisted client is and where to write to them (RF-12, CL-06). */
interface AssistedRecipients
{
    /** The assisted client of the current barbershop; null when they do not exist there or have no email, as the barbershop may register them (CL-06). */
    public function ofAssistedClient(string $assistedClientId): ?Recipient;
}
