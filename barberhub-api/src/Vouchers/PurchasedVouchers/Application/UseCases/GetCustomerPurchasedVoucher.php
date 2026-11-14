<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\UseCases;

use Src\Vouchers\PurchasedVouchers\Application\Contracts\CustomerMemberships;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotFoundException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\CustomerMembershipId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\PurchasedVoucherId;

final readonly class GetCustomerPurchasedVoucher
{
    /** Receive the repository and the port that finds the customer role of a person. */
    public function __construct(private PurchasedVoucherRepository $vouchers, private CustomerMemberships $customers) {}

    /**
     * Read a voucher of the current barbershop that belongs to the person. One that does not exist, is of another barbershop or of
     * another customer, or a person without a customer role there, all read the same (UP-06, MT-03). It creates nothing.
     *
     * @throws VoucherNotFoundException
     */
    public function execute(string $userId, string $voucherId): PurchasedVoucher
    {
        $customer = $this->customers->find($userId) ?? throw VoucherNotFoundException::create();
        $voucher = $this->vouchers->find(new PurchasedVoucherId($voucherId)) ?? throw VoucherNotFoundException::create();

        return $voucher->customerMembershipId->equals(new CustomerMembershipId($customer)) ? $voucher : throw VoucherNotFoundException::create();
    }
}
