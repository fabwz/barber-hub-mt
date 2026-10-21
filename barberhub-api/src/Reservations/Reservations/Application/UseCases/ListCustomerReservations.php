<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use Src\Reservations\Reservations\Application\AttachNames;
use Src\Reservations\Reservations\Application\Contracts\CustomerDirectory;
use Src\Reservations\Reservations\Application\DTOs\ReservationSearch;
use Src\Reservations\Reservations\Application\DTOs\ReservationView;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationFilter;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;

final readonly class ListCustomerReservations
{
    /** Receive the reservation repository, the port that finds the customer role and the names attacher. */
    public function __construct(
        private ReservationRepository $reservations,
        private CustomerDirectory $customers,
        private AttachNames $attachNames,
    ) {}

    /**
     * List one page of the reservations a person made in the current barbershop (CL-04). The scope is their own customer
     * role, found from the session and never from the request; a person without one has not reserved here yet, so the
     * page is empty, not an error (RD-18). The names of other customers are not read, because these are all their own.
     *
     * @return Page<ReservationView>
     */
    public function execute(string $userId, ReservationSearch $search, PageRequest $page): Page
    {
        $membershipId = $this->customers->find($userId);

        if ($membershipId === null) {
            return new Page([], 0, $page);
        }

        $filter = new ReservationFilter(new CustomerMembershipId($membershipId), null, $search->status, $search->day);

        return $this->attachNames->to($this->reservations->page($filter, $page), false);
    }
}
