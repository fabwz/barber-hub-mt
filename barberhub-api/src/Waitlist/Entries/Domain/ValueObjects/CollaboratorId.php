<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** The collaborator the customer asks for, when they ask for one. */
final readonly class CollaboratorId extends Identifier {}
