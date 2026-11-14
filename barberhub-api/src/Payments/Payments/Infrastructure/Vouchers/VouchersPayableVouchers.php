<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Vouchers;

use DateTimeImmutable;
use Src\Payments\Payments\Application\Contracts\PayableVouchers;
use Src\Payments\Payments\Application\DTOs\PayableVoucher;
use Src\Payments\Payments\Domain\Exceptions\PaymentNotFoundException;
use Src\Vouchers\PurchasedVouchers\Application\UseCases\GetCustomerPurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Application\UseCases\StartVoucherPurchase;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotFoundException;

final readonly class VouchersPayableVouchers implements PayableVouchers
{
    /** Receive the Vouchers use cases that register a purchase and show a customer their own voucher. */
    public function __construct(
        private StartVoucherPurchase $startPurchase,
        private GetCustomerPurchasedVoucher $getCustomerVoucher,
    ) {}

    /** Register the purchase through Vouchers: a voucher that is not on sale in the barbershop reads like one that does not exist. */
    public function begin(string $userId, string $voucherDefinitionId, DateTimeImmutable $now): PayableVoucher
    {
        try {
            return $this->payable($this->startPurchase->execute($userId, $voucherDefinitionId, $now));
        } catch (VoucherNotFoundException) {
            throw PaymentNotFoundException::create();
        }
    }

    /** Read the voucher through Vouchers: one of another customer or barbershop reads like one that does not exist. */
    public function ofCustomer(string $userId, string $voucherId): PayableVoucher
    {
        try {
            return $this->payable($this->getCustomerVoucher->execute($userId, $voucherId));
        } catch (VoucherNotFoundException) {
            throw PaymentNotFoundException::create();
        }
    }

    private function payable(PurchasedVoucher $voucher): PayableVoucher
    {
        return new PayableVoucher($voucher->id->value, $voucher->price);
    }
}
