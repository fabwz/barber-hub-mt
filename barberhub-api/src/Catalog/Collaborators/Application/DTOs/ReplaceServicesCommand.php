<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\DTOs;

final readonly class ReplaceServicesCommand
{
    /**
     * Carry the whole set of services that replaces the ones a collaborator offers.
     *
     * @param  list<string>  $serviceIds
     */
    public function __construct(
        public string $collaboratorId,
        public array $serviceIds,
    ) {}
}
