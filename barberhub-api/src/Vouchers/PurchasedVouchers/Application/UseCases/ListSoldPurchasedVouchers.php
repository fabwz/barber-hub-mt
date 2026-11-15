<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\UseCases;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Vouchers\PurchasedVouchers\Application\BuildPurchasedVoucherViews;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\PurchasedVoucherView;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;

final readonly class ListSoldPurchasedVouchers
{
    /** Receive the repository and the builder of the views. */
    public function __construct(private PurchasedVoucherRepository $vouchers, private BuildPurchasedVoucherViews $views) {}

    /**
     * List one page of the paid vouchers sold in the current barbershop, each with the name of its customer (never their
     * contact), its balance, validity and record (RF-29, MT-02). Only the owner reaches it; the barbershop is the one of the
     * route, so no voucher of another barbershop can appear.
     *
     * @return Page<PurchasedVoucherView>
     */
    public function execute(PageRequest $page, DateTimeImmutable $now): Page
    {
        return $this->views->from($this->vouchers->pageOfBarbershop($page), true, $now);
    }
}
