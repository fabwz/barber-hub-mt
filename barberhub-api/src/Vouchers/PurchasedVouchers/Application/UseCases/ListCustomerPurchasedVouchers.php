<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\UseCases;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Vouchers\PurchasedVouchers\Application\BuildPurchasedVoucherViews;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\CustomerMemberships;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\PurchasedVoucherView;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\CustomerMembershipId;

final readonly class ListCustomerPurchasedVouchers
{
    /** Receive the repository, the port that finds the customer role of a person and the builder of the views. */
    public function __construct(
        private PurchasedVoucherRepository $vouchers,
        private CustomerMemberships $customers,
        private BuildPurchasedVoucherViews $views,
    ) {}

    /**
     * List one page of the paid vouchers a person bought in the current barbershop, with their balance, validity and record
     * (RF-29, CL-04) and the services each one covers, read from its live definition, withdrawn or not (BO-06). The scope is their own customer role, found from the session and never from the request; a person without
     * one has bought nothing here yet, so the page is empty, not an error (RD-18). It creates nothing.
     *
     * @return Page<PurchasedVoucherView>
     */
    public function execute(string $userId, PageRequest $page, DateTimeImmutable $now): Page
    {
        $membershipId = $this->customers->find($userId);

        if ($membershipId === null) {
            return new Page([], 0, $page);
        }

        return $this->views->from($this->vouchers->pageOfCustomer(new CustomerMembershipId($membershipId), $page), false, $now, true);
    }
}
