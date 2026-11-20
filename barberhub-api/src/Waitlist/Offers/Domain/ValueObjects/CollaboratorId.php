<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** The collaborator whose space is offered. */
final readonly class CollaboratorId extends Identifier {}
