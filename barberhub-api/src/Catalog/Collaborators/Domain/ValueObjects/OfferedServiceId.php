<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** Identity of a service as the collaborator knows it: the domain of Services belongs to another module. */
final readonly class OfferedServiceId extends Identifier {}
