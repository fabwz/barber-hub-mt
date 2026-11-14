<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\UseCases;

use DateTimeImmutable;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\CustomerMemberships;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\SellableVouchers;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotFoundException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\CustomerMembershipId;

final readonly class StartVoucherPurchase
{
    /** Receive the repository, the ports to the vouchers on sale and to the memberships, and the barbershop context. */
    public function __construct(
        private PurchasedVoucherRepository $vouchers,
        private SellableVouchers $sellable,
        private CustomerMemberships $customers,
        private BarbershopContext $context,
    ) {}

    /**
     * Register the purchase of a voucher by a person (RF-27). It locks the voucher on sale first, so the terms it copies are the
     * ones on sale at this instant, then creates the customer role of the person if they have none (UP-09) and stores the voucher
     * waiting for its payment, with no sessions and no expiry until it is activated (BO-02). If the person already has a voucher of
     * this kind waiting for its payment it is returned and nothing is created, so a repeated press or a retry after a refused payment
     * does not pile up unpaid vouchers; the lock on the voucher on sale makes two presses at once run in turn. Nothing opens a
     * transaction here: the caller does, so a failure after this point takes the role and the voucher with it.
     *
     * @throws VoucherNotFoundException when the voucher is not on sale in the barbershop
     */
    public function execute(string $userId, string $voucherDefinitionId, DateTimeImmutable $now): PurchasedVoucher
    {
        $onSale = $this->sellable->lockOnSale($voucherDefinitionId);
        $customer = new CustomerMembershipId($this->customers->ensure($userId));

        $waiting = $this->vouchers->findPendingOfCustomer($customer, $onSale->id);
        if ($waiting !== null) {
            return $waiting;
        }

        $voucher = PurchasedVoucher::purchase(
            $this->vouchers->nextId(),
            $this->context->current(),
            $customer,
            $onSale->id,
            $onSale->name,
            $onSale->price,
            $onSale->sessionsCount,
            $onSale->validityDays,
            $now,
        );
        $this->vouchers->save($voucher);

        return $voucher;
    }
}
