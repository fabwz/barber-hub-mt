<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

enum NextActionType: string
{
    case Redirect = 'redirect';
    case Instructions = 'instructions';
}
