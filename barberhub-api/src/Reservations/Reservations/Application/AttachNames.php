<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\Contracts\AssistedCustomers;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorDirectory;
use Src\Reservations\Reservations\Application\Contracts\CustomerDirectory;
use Src\Reservations\Reservations\Application\Contracts\ServiceNames;
use Src\Reservations\Reservations\Application\DTOs\AssistedClientContact;
use Src\Reservations\Reservations\Application\DTOs\ReservationView;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Shared\Domain\ValueObjects\Page;

final readonly class AttachNames
{
    /** Receive the ports that tell the names of the services, the collaborators, the customers and the assisted clients. */
    public function __construct(
        private ServiceNames $services,
        private CollaboratorDirectory $collaborators,
        private CustomerDirectory $customers,
        private AssistedCustomers $assisted,
    ) {}

    /**
     * Pair each reservation of the page with the names it shows. Whatever the size of the page, it reads the services and
     * the collaborators once and, for the staff, the customers once (the accounts in one read and the assisted clients in another), so a listing never reads
     * row by row. An empty page reads nothing. The status of each is the one it shows at `$now`. With `$withContact`, the phone of an assisted client
     * comes too: the owner calls whoever the barbershop registered, and nobody else sees it (CO-04).
     *
     * @param  Page<Reservation>  $page
     * @return Page<ReservationView>
     */
    public function to(Page $page, bool $withCustomers, DateTimeImmutable $now, bool $withContact = false): Page
    {
        if ($page->items === []) {
            return new Page([], $page->total, $page->request);
        }

        $services = $this->services->all();
        $collaborators = $this->collaborators->names();
        [$accounts, $assisted] = $withCustomers ? $this->customersOf($page->items) : [[], []];

        return $page->map(fn (Reservation $reservation): ReservationView => $this->view($reservation, $services, $collaborators, $accounts, $assisted, $withCustomers, $withContact, $now));
    }

    /** Pair one reservation with the same names; it reads the same lists, never row by row. */
    public function one(Reservation $reservation, bool $withCustomer, DateTimeImmutable $now, bool $withContact = false): ReservationView
    {
        [$accounts, $assisted] = $withCustomer ? $this->customersOf([$reservation]) : [[], []];

        return $this->view($reservation, $this->services->all(), $this->collaborators->names(), $accounts, $assisted, $withCustomer, $withContact, $now);
    }

    /**
     * The names of the accounts behind the customer roles and the contacts of the assisted clients of the reservations, each in one read.
     *
     * @param  list<Reservation>  $reservations
     * @return array{0: array<string, string>, 1: array<string, AssistedClientContact>}
     */
    private function customersOf(array $reservations): array
    {
        $roles = [];
        $assistedIds = [];

        foreach ($reservations as $reservation) {
            if ($reservation->customerMembershipId !== null) {
                $roles[] = $reservation->customerMembershipId->value;
            }

            if ($reservation->assistedClientId !== null) {
                $assistedIds[] = $reservation->assistedClientId->value;
            }
        }

        return [
            $roles === [] ? [] : $this->customers->namesOf(array_values(array_unique($roles))),
            $assistedIds === [] ? [] : $this->assisted->contactsOf(array_values(array_unique($assistedIds))),
        ];
    }

    /**
     * Build the view of one reservation from the lists already read.
     *
     * @param  array<string, string>  $services
     * @param  array<string, string>  $collaborators
     * @param  array<string, string>  $accounts
     * @param  array<string, AssistedClientContact>  $assisted
     */
    private function view(Reservation $reservation, array $services, array $collaborators, array $accounts, array $assisted, bool $withCustomer, bool $withContact, DateTimeImmutable $now): ReservationView
    {
        $contact = $reservation->assistedClientId === null ? null : $assisted[$reservation->assistedClientId->value] ?? null;
        $name = $reservation->customerMembershipId === null ? $contact?->name : $accounts[$reservation->customerMembershipId->value] ?? null;

        return new ReservationView(
            $reservation,
            $services[$reservation->serviceId->value] ?? '',
            $collaborators[$reservation->collaboratorId->value] ?? '',
            $withCustomer ? $name ?? '' : null,
            $reservation->statusAt($now),
            $reservation->expiresAt,
            null,
            $withCustomer && $withContact ? $contact?->phone : null,
        );
    }
}
