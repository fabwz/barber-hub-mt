<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** The service a person waits for. */
final readonly class ServiceId extends Identifier {}
