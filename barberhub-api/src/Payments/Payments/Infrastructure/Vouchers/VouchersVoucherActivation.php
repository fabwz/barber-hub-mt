<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Vouchers;

use DateTimeImmutable;
use Src\Payments\Payments\Application\Contracts\VoucherActivation;
use Src\Vouchers\PurchasedVouchers\Application\UseCases\ActivatePurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherAlreadyActiveException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotFoundException;

final readonly class VouchersVoucherActivation implements VoucherActivation
{
    /** Receive the Vouchers use case that activates a voucher whose payment was confirmed. */
    public function __construct(private ActivatePurchasedVoucher $activateVoucher) {}

    /** Activate through Vouchers; whatever stops the activation of a voucher answers false instead of failing. */
    public function activate(string $voucherId, DateTimeImmutable $now): bool
    {
        try {
            $this->activateVoucher->execute($voucherId, $now);
        } catch (VoucherAlreadyActiveException|VoucherNotFoundException) {
            return false;
        }

        return true;
    }
}
