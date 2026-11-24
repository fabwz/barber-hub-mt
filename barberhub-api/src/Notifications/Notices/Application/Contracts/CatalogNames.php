<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\Contracts;

/** The names the text of a notice shows, from the catalog of the current barbershop. */
interface CatalogNames
{
    /** The name of a service; empty when it is not found. */
    public function serviceName(string $serviceId): string;

    /** The name of a collaborator; empty when it is not found. */
    public function collaboratorName(string $collaboratorId): string;
}
