<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\Contracts;

use Src\Notifications\Notices\Application\DTOs\Branding;

/** The face of the current barbershop in its notices (MT-08). */
interface BarbershopBranding
{
    /** Its name and the absolute address of its logo. */
    public function current(): Branding;
}
