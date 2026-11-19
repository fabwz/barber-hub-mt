<?php

declare(strict_types=1);

namespace Tests\Support\Waitlist;

use DateTimeImmutable;
use Src\Waitlist\Entries\Application\Contracts\OpenSpaces;
use Src\Waitlist\Entries\Application\Contracts\WaitlistCatalog;
use Src\Waitlist\Entries\Application\Contracts\WaitlistSettings;
use Src\Waitlist\Entries\Domain\ValueObjects\OpenSpace;

/** The switch, the catalog and the free spaces of the barbershop of the test, in one double: the waitlist starts on, with no service, no collaborator and no space. */
final class InMemoryWaitlistPorts implements OpenSpaces, WaitlistCatalog, WaitlistSettings
{
    public bool $enabled = true;

    /** @var list<string> */
    public array $services = [];

    /** @var list<string> */
    public array $collaborators = [];

    /** @var list<OpenSpace> */
    public array $spaces = [];

    /** How many times the spaces were asked for. */
    public int $asked = 0;

    /** Tell whether the waitlist is on. */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** Whether the service is of the barbershop. */
    public function hasService(string $serviceId): bool
    {
        return in_array($serviceId, $this->services, true);
    }

    /** Whether the collaborator is of the barbershop. */
    public function hasCollaborator(string $collaboratorId): bool
    {
        return in_array($collaboratorId, $this->collaborators, true);
    }

    /**
     * The spaces the test set up.
     *
     * @return list<OpenSpace>
     */
    public function on(string $serviceId, string $date, ?string $collaboratorId, DateTimeImmutable $now): array
    {
        $this->asked++;

        return $this->spaces;
    }
}
