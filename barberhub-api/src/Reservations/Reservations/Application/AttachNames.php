<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application;

use Src\Reservations\Reservations\Application\Contracts\CollaboratorDirectory;
use Src\Reservations\Reservations\Application\Contracts\CustomerDirectory;
use Src\Reservations\Reservations\Application\Contracts\ServiceNames;
use Src\Reservations\Reservations\Application\DTOs\ReservationView;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Shared\Domain\ValueObjects\Page;

final readonly class AttachNames
{
    /** Receive the ports that tell the names of the services, the collaborators and the customers. */
    public function __construct(
        private ServiceNames $services,
        private CollaboratorDirectory $collaborators,
        private CustomerDirectory $customers,
    ) {}

    /**
     * Pair each reservation of the page with the names it shows. Whatever the size of the page, it reads the services and
     * the collaborators once and, for the staff, the customers once, so a listing never reads row by row. An empty page
     * reads nothing.
     *
     * @param  Page<Reservation>  $page
     * @return Page<ReservationView>
     */
    public function to(Page $page, bool $withCustomers): Page
    {
        if ($page->items === []) {
            return new Page([], $page->total, $page->request);
        }

        $services = $this->services->all();
        $collaborators = $this->collaborators->names();
        $customers = $withCustomers
            ? $this->customers->namesOf(array_values(array_unique(array_map(fn (Reservation $reservation): string => $reservation->customerMembershipId->value, $page->items))))
            : [];

        return $page->map(fn (Reservation $reservation): ReservationView => new ReservationView(
            $reservation,
            $services[$reservation->serviceId->value] ?? '',
            $collaborators[$reservation->collaboratorId->value] ?? '',
            $withCustomers ? $customers[$reservation->customerMembershipId->value] ?? '' : null,
        ));
    }

    /** Pair one reservation with the same names; it reads the same lists, never row by row. */
    public function one(Reservation $reservation, bool $withCustomer): ReservationView
    {
        $customers = $withCustomer ? $this->customers->namesOf([$reservation->customerMembershipId->value]) : [];

        return new ReservationView(
            $reservation,
            $this->services->all()[$reservation->serviceId->value] ?? '',
            $this->collaborators->names()[$reservation->collaboratorId->value] ?? '',
            $withCustomer ? $customers[$reservation->customerMembershipId->value] ?? '' : null,
        );
    }
}
