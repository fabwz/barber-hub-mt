<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\UseCases;

use DateTimeImmutable;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherAlreadyActiveException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotFoundException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\PurchasedVoucherId;

final readonly class ActivatePurchasedVoucher
{
    /** Receive the purchased voucher repository port. */
    public function __construct(private PurchasedVoucherRepository $vouchers) {}

    /**
     * Activate a voucher of the current barbershop whose payment the provider confirmed (BO-02, PA-03): it gets all its sessions, its
     * validity counts from this instant and the entry of the purchase joins the record (BO-09). The voucher is locked, so two
     * confirmations of the same payment run in turn; the second finds it active and fails, and the entry of the purchase is unique in
     * the database too, so the sessions are never granted twice (PA-05). Called inside the transaction that approves the payment.
     *
     * @throws VoucherNotFoundException when it is not one of the current barbershop
     * @throws VoucherAlreadyActiveException when it was already activated
     */
    public function execute(string $voucherId, DateTimeImmutable $now): PurchasedVoucher
    {
        $voucher = $this->vouchers->findForUpdate(new PurchasedVoucherId($voucherId)) ?? throw VoucherNotFoundException::create();
        $voucher->activate($now);
        $this->vouchers->save($voucher);

        return $voucher;
    }
}
