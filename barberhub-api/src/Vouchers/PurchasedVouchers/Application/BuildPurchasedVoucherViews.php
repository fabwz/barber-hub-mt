<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\CustomerMemberships;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\DefinitionServices;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\PurchasedVoucherView;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\VoucherMovement;

final readonly class BuildPurchasedVoucherViews
{
    /** Receive the repository that reads the record and the ports that tell the names of the customers and the services a voucher covers. */
    public function __construct(
        private PurchasedVoucherRepository $vouchers,
        private CustomerMemberships $customers,
        private DefinitionServices $definitionServices,
    ) {}

    /**
     * Read the record of every voucher of the page at once and, if asked, the name of each customer and the services each voucher
     * covers, and tell what each voucher shows at the instant: the sum of its record, or zero once it expired (BO-07, BO-09). The
     * names are only for the owner; the customer reads their own vouchers, so they have no use for them. The covered services are
     * read from the live definition, withdrawn or not (BO-06), and only for the customer: the voucher keeps no copy of them.
     *
     * @param  Page<PurchasedVoucher>  $page
     * @return Page<PurchasedVoucherView>
     */
    public function from(Page $page, bool $withNames, DateTimeImmutable $now, bool $withServices = false): Page
    {
        $record = $this->vouchers->movementsOfMany(array_map(fn (PurchasedVoucher $voucher) => $voucher->id, $page->items));
        $names = $withNames
            ? $this->customers->namesOf(array_values(array_unique(array_map(fn (PurchasedVoucher $voucher): string => $voucher->customerMembershipId->value, $page->items))))
            : [];
        $services = $withServices
            ? $this->definitionServices->of(array_values(array_unique(array_map(fn (PurchasedVoucher $voucher): string => $voucher->voucherDefinitionId->value, $page->items))))
            : [];

        return $page->map(function (PurchasedVoucher $voucher) use ($record, $names, $withNames, $services, $withServices, $now): PurchasedVoucherView {
            $movements = $record[$voucher->id->value] ?? [];
            $expired = $voucher->isExpiredAt($now);

            return new PurchasedVoucherView(
                $voucher,
                $movements,
                $expired ? 0 : VoucherMovement::balanceOf($movements),
                $expired,
                $withNames ? ($names[$voucher->customerMembershipId->value] ?? '') : null,
                $withServices ? ($services[$voucher->voucherDefinitionId->value] ?? []) : null,
            );
        });
    }
}
