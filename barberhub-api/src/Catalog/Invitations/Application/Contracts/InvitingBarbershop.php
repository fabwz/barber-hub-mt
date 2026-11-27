<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Application\Contracts;

interface InvitingBarbershop
{
    /** The name of the current barbershop, which the person sees before accepting. */
    public function name(): string;
}
