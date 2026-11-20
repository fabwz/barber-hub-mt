<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\Contracts;

interface ServiceMinutes
{
    /**
     * The minutes that each service needs from a collaborator, the cleaning included (DI-01), for the services that the collaborator offers
     * and that a customer can still book (CO-01, SV-04). A service the collaborator does not offer, an inactive one, and every service of an
     * inactive collaborator are not in the answer, so they are never compatible with a space of that collaborator.
     *
     * @param  list<string>  $serviceIds
     * @return array<string, int> The minutes by service.
     */
    public function needed(string $collaboratorId, array $serviceIds): array;
}
