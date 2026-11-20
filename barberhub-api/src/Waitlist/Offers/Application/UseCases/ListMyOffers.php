<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\UseCases;

use DateTimeImmutable;
use Src\Waitlist\Offers\Application\Contracts\CustomerMemberships;
use Src\Waitlist\Offers\Application\Contracts\LineEntries;
use Src\Waitlist\Offers\Application\DTOs\LineRow;
use Src\Waitlist\Offers\Application\DTOs\OfferView;
use Src\Waitlist\Offers\Domain\Contracts\OfferRepository;
use Src\Waitlist\Offers\Domain\Entities\Offer;
use Src\Waitlist\Offers\Domain\ValueObjects\WaitlistEntryId;

final readonly class ListMyOffers
{
    /** Receive the repository of the offers and the ports to the customer roles and to the line. */
    public function __construct(
        private OfferRepository $offers,
        private LineEntries $line,
        private CustomerMemberships $customers,
    ) {}

    /**
     * List the offers a person can still answer in the current barbershop: the pending ones, inside their 15 minutes, of their own rows that have
     * an offer in course (RF-32, CL-04), from the first one made. The scope is their own customer role, found from the session and never from the
     * request; a person without one has none, so the list is empty, not an error (RD-18). It creates nothing. A customer has at most 3 active
     * rows, so it is not paged.
     *
     * @return list<OfferView>
     */
    public function execute(string $userId, DateTimeImmutable $now): array
    {
        $membership = $this->customers->find($userId);

        if ($membership === null) {
            return [];
        }

        $rows = [];

        foreach ($this->line->activeOf($membership) as $row) {
            if ($row->offered) {
                $rows[$row->id] = $row;
            }
        }

        return array_map(
            fn (Offer $offer): OfferView => new OfferView($offer, $this->serviceOf($rows, $offer)),
            $this->offers->pendingOfEntries(array_map(fn (string $id): WaitlistEntryId => new WaitlistEntryId($id), array_keys($rows)), $now),
        );
    }

    /**
     * The service the row of the offer asks for.
     *
     * @param  array<string, LineRow>  $rows
     */
    private function serviceOf(array $rows, Offer $offer): string
    {
        return $rows[$offer->entryId->value]->serviceId;
    }
}
