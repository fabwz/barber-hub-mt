<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\Entities;

use Src\Catalog\Collaborators\Domain\ValueObjects\CollaboratorId;
use Src\Catalog\Collaborators\Domain\ValueObjects\OfferedServiceId;
use Src\Catalog\Collaborators\Domain\ValueObjects\WorkSchedule;
use Src\Shared\Domain\ValueObjects\UserId;

final class Collaborator
{
    /**
     * Hold the full state of a collaborator profile; the barbershop it belongs to is the context of the repository.
     *
     * @param  list<OfferedServiceId>  $services
     */
    private function __construct(
        public readonly CollaboratorId $id,
        public readonly UserId $userId,
        public private(set) bool $active,
        public private(set) array $services,
        public private(set) WorkSchedule $schedule,
    ) {}

    /** Register a profile for an account, active from the start, with no services and no working hours (RF-05). */
    public static function create(CollaboratorId $id, UserId $userId): self
    {
        return new self($id, $userId, true, [], WorkSchedule::empty());
    }

    /**
     * Rebuild a profile from its stored state.
     *
     * @param  list<OfferedServiceId>  $services
     */
    public static function reconstitute(CollaboratorId $id, UserId $userId, bool $active, array $services, WorkSchedule $schedule): self
    {
        return new self($id, $userId, $active, $services, $schedule);
    }

    /**
     * Replace the services the collaborator offers; a repeated service counts once (CO-01).
     *
     * @param  list<OfferedServiceId>  $services
     */
    public function offer(array $services): void
    {
        $unique = [];

        foreach ($services as $service) {
            $unique[$service->value] = $service;
        }

        $this->services = array_values($unique);
    }

    /** Replace the weekly working hours. */
    public function work(WorkSchedule $schedule): void
    {
        $this->schedule = $schedule;
    }

    /** Tell whether the collaborator can be booked for the service (CO-01). */
    public function offers(OfferedServiceId $service): bool
    {
        return array_any($this->services, fn (OfferedServiceId $offered): bool => $offered->equals($service));
    }

    /** Receive bookings again. */
    public function activate(): void
    {
        $this->active = true;
    }

    /** Stop receiving bookings without deleting the profile: the history keeps pointing at it. */
    public function deactivate(): void
    {
        $this->active = false;
    }
}
