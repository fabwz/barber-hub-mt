<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\CustomerMemberships;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\PurchasedVoucherView;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\VoucherMovement;

final readonly class BuildPurchasedVoucherViews
{
    /** Receive the repository that reads the record and the port that tells the names of the customers. */
    public function __construct(private PurchasedVoucherRepository $vouchers, private CustomerMemberships $customers) {}

    /**
     * Read the record of every voucher of the page at once and, if asked, the name of each customer, and tell what each voucher
     * shows at the instant: the sum of its record, or zero once it expired (BO-07, BO-09). The names are only for the owner;
     * the customer reads their own vouchers, so they have no use for them.
     *
     * @param  Page<PurchasedVoucher>  $page
     * @return Page<PurchasedVoucherView>
     */
    public function from(Page $page, bool $withNames, DateTimeImmutable $now): Page
    {
        $record = $this->vouchers->movementsOfMany(array_map(fn (PurchasedVoucher $voucher) => $voucher->id, $page->items));
        $names = $withNames
            ? $this->customers->namesOf(array_values(array_unique(array_map(fn (PurchasedVoucher $voucher): string => $voucher->customerMembershipId->value, $page->items))))
            : [];

        return $page->map(function (PurchasedVoucher $voucher) use ($record, $names, $withNames, $now): PurchasedVoucherView {
            $movements = $record[$voucher->id->value] ?? [];
            $expired = $voucher->isExpiredAt($now);

            return new PurchasedVoucherView(
                $voucher,
                $movements,
                $expired ? 0 : VoucherMovement::balanceOf($movements),
                $expired,
                $withNames ? ($names[$voucher->customerMembershipId->value] ?? '') : null,
            );
        });
    }
}
