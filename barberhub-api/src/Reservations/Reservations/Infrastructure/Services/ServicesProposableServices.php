<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Services;

use Src\Catalog\Services\Application\DTOs\PublicService;
use Src\Catalog\Services\Application\UseCases\ListPublicServices;
use Src\Reservations\Reservations\Application\Contracts\ProposableServices;
use Src\Reservations\Reservations\Application\DTOs\ProposableService;
use Src\Shared\Domain\ValueObjects\Money;

final readonly class ServicesProposableServices implements ProposableServices
{
    /** Receive the Services use case that lists what a customer may book. */
    public function __construct(private ListPublicServices $listServices) {}

    /**
     * Read the active services of the current barbershop through the Services module.
     *
     * @return list<ProposableService>
     */
    public function active(): array
    {
        return array_map(fn (PublicService $service): ProposableService => new ProposableService($service->id, $service->name, Money::of($service->price)), $this->listServices->execute());
    }
}
