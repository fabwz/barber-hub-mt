<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\ValueObjects;

use Src\Shared\Domain\ValueObjects\Identifier;

/** The service the customer waits for. */
final readonly class ServiceId extends Identifier {}
