<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Catalog;

use Src\Availability\Slots\Domain\Services\SlotCalculator;
use Src\Catalog\Collaborators\Application\UseCases\GetCollaborator;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Catalog\Collaborators\Domain\ValueObjects\OfferedServiceId;
use Src\Catalog\Services\Application\UseCases\GetPublicService;
use Src\Waitlist\Offers\Application\Contracts\ServiceMinutes;

final readonly class CatalogServiceMinutes implements ServiceMinutes
{
    /** Receive the Collaborators use case that gives a collaborator with their services and the Services one that gives an active service. */
    public function __construct(
        private GetCollaborator $getCollaborator,
        private GetPublicService $getService,
    ) {}

    /**
     * The minutes each service needs, cleaning included, for the services the collaborator offers and a customer can book, through the Catalog
     * module. The cleaning is the one the availability adds when it computes a space, so a hole that fits here fits there.
     *
     * @param  list<string>  $serviceIds
     * @return array<string, int>
     */
    public function needed(string $collaboratorId, array $serviceIds): array
    {
        try {
            $collaborator = $this->getCollaborator->execute($collaboratorId)->collaborator;
        } catch (CollaboratorNotFoundException) {
            return [];
        }

        if (! $collaborator->active) {
            return [];
        }

        $minutes = [];

        foreach ($serviceIds as $serviceId) {
            $service = $collaborator->offers(new OfferedServiceId($serviceId)) ? $this->getService->execute($serviceId) : null;

            if ($service !== null) {
                $minutes[$serviceId] = $service->durationMinutes + SlotCalculator::CLEANUP_MINUTES;
            }
        }

        return $minutes;
    }
}
