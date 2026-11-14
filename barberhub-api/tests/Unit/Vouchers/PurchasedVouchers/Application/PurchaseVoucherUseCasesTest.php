<?php

declare(strict_types=1);

namespace Tests\Unit\Vouchers\PurchasedVouchers\Application;

use PHPUnit\Framework\TestCase;
use Src\Identity\Memberships\Domain\Exceptions\StaffCannotBeCustomerException;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Vouchers\PurchasedVouchers\Application\UseCases\ActivatePurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Application\UseCases\GetCustomerPurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Application\UseCases\StartVoucherPurchase;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherAlreadyActiveException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotFoundException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\PurchasedVoucherId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\VoucherMovementType;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\VoucherStatus;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Vouchers\InMemoryCustomerMemberships;
use Tests\Support\Vouchers\InMemoryPurchasedVoucherRepository;
use Tests\Support\Vouchers\InMemorySellableVouchers;
use Tests\Support\Vouchers\VoucherFactory;

/** The use cases of the purchase of a voucher and of its activation (RF-27, BO-02, UP-07, UP-09, BO-09). */
final class PurchaseVoucherUseCasesTest extends TestCase
{
    private const string ANA = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0b01';

    private const string LUIS = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0b02';

    private InMemoryPurchasedVoucherRepository $vouchers;

    private InMemorySellableVouchers $sellable;

    private InMemoryCustomerMemberships $customers;

    private StartVoucherPurchase $start;

    /** "Bono 5 cortes" on sale in the barbershop of the fixtures. */
    protected function setUp(): void
    {
        $this->vouchers = new InMemoryPurchasedVoucherRepository;
        $this->sellable = new InMemorySellableVouchers;
        $this->sellable->add(VoucherFactory::definition());
        $this->customers = new InMemoryCustomerMemberships;
        $this->start = new StartVoucherPurchase($this->vouchers, $this->sellable, $this->customers, new FixedBarbershopContext(new BarbershopId(VoucherFactory::BARBERSHOP)));
    }

    /** The purchase copies what was on sale and waits for its payment: no sessions, no dates, nothing in the record (BO-02). */
    public function test_a_purchase_is_registered_pending_with_a_copy_of_the_terms(): void
    {
        $voucher = $this->start->execute(self::ANA, VoucherFactory::DEFINITION, VoucherFactory::now());

        $this->assertSame(VoucherStatus::Pending, $voucher->status);
        $this->assertSame(['Bono 5 cortes', 30000, 5, 90], [$voucher->name->value, $voucher->price->colones, $voucher->sessionsCount->value, $voucher->validityDays->value]);
        $this->assertSame(0, $voucher->balance->value);
        $this->assertNull($voucher->activatedAt);
        $this->assertNull($voucher->expiresAt);
        $this->assertSame([], $this->vouchers->movementsOf($voucher->id));
        $this->assertSame(VoucherFactory::BARBERSHOP, $voucher->barbershopId->value);
        $this->assertSame(VoucherFactory::DEFINITION, $voucher->voucherDefinitionId->value);
    }

    /** The definition is locked before anything is copied, so an edit cannot cross with the sale (RF-26, RF-27). */
    public function test_the_voucher_on_sale_is_locked_before_it_is_copied(): void
    {
        $this->start->execute(self::ANA, VoucherFactory::DEFINITION, VoucherFactory::now());

        $this->assertSame([VoucherFactory::DEFINITION], $this->sellable->locked);
    }

    /** A person without a role gets their customer role with the purchase, once (UP-09). */
    public function test_the_customer_role_is_created_with_the_first_purchase_only(): void
    {
        $first = $this->start->execute(self::ANA, VoucherFactory::DEFINITION, VoucherFactory::now());
        $this->vouchers->save($first);
        $this->start->execute(self::LUIS, VoucherFactory::DEFINITION, VoucherFactory::now());

        $this->assertSame(2, $this->customers->created());
        $this->assertNotNull($this->customers->find(self::ANA));
        $this->assertSame($this->customers->find(self::ANA), $first->customerMembershipId->value);
    }

    /** The owner or a collaborator cannot be a customer of their own barbershop and nothing is stored (UP-07). */
    public function test_a_staff_member_is_refused_and_nothing_is_stored(): void
    {
        $this->customers->makeStaff(self::ANA);

        try {
            $this->start->execute(self::ANA, VoucherFactory::DEFINITION, VoucherFactory::now());
            $this->fail('A staff member bought a voucher of their barbershop.');
        } catch (StaffCannotBeCustomerException) {
            $this->assertSame(0, $this->vouchers->count());
        }
    }

    /** A voucher that is not on sale, or does not exist, reads as missing and creates neither the role nor the voucher (MT-03). */
    public function test_a_voucher_not_on_sale_creates_nothing(): void
    {
        $withdrawn = VoucherFactory::definition(id: '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0b0a');
        $withdrawn->withdraw(VoucherFactory::now());
        $this->sellable->add($withdrawn);

        foreach (['0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0b0a', '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0b0b'] as $definition) {
            try {
                $this->start->execute(self::ANA, $definition, VoucherFactory::now());
                $this->fail('A voucher that is not on sale was bought.');
            } catch (VoucherNotFoundException) {
                $this->assertSame(0, $this->vouchers->count());
                $this->assertSame(0, $this->customers->created());
            }
        }
    }

    /** A person who already waits for the payment of this voucher gets that one back instead of a second unpaid voucher. */
    public function test_a_repeated_purchase_returns_the_voucher_that_waits_for_its_payment(): void
    {
        $first = $this->start->execute(self::ANA, VoucherFactory::DEFINITION, VoucherFactory::now());
        $this->vouchers->save($first);

        $again = $this->start->execute(self::ANA, VoucherFactory::DEFINITION, VoucherFactory::now());

        $this->assertSame($first->id->value, $again->id->value);
        $this->assertSame(1, $this->vouchers->count());
    }

    /** Once it is paid, buying the same voucher again is a new purchase. */
    public function test_after_the_activation_a_new_purchase_is_a_new_voucher(): void
    {
        $first = $this->start->execute(self::ANA, VoucherFactory::DEFINITION, VoucherFactory::now());
        $this->vouchers->save($first);
        new ActivatePurchasedVoucher($this->vouchers)->execute($first->id->value, VoucherFactory::now());

        $second = $this->start->execute(self::ANA, VoucherFactory::DEFINITION, VoucherFactory::now());

        $this->assertNotSame($first->id->value, $second->id->value);
        $this->assertSame(VoucherStatus::Pending, $second->status);
    }

    /** The activation gives all the sessions, counts the validity from that instant and leaves one purchase entry (BO-02, BO-09, RF-27). */
    public function test_the_activation_gives_the_sessions_and_the_validity_and_records_the_purchase(): void
    {
        $voucher = $this->start->execute(self::ANA, VoucherFactory::DEFINITION, VoucherFactory::now());
        $this->vouchers->save($voucher);
        $activation = VoucherFactory::now()->modify('+2 days');

        $active = new ActivatePurchasedVoucher($this->vouchers)->execute($voucher->id->value, $activation);

        $this->assertSame(VoucherStatus::Active, $active->status);
        $this->assertSame(5, $active->balance->value);
        $this->assertEquals($activation, $active->activatedAt);
        $this->assertEquals($activation->modify('+90 days'), $active->expiresAt);
        $movements = $this->vouchers->movementsOf($voucher->id);
        $this->assertCount(1, $movements);
        $this->assertSame([VoucherMovementType::Purchase, 5, null], [$movements[0]->type, $movements[0]->sessionsDelta, $movements[0]->reservationId]);
    }

    /** A second activation of the same voucher fails and leaves the balance and the record as they were (PA-05). */
    public function test_a_second_activation_changes_nothing(): void
    {
        $voucher = $this->start->execute(self::ANA, VoucherFactory::DEFINITION, VoucherFactory::now());
        $this->vouchers->save($voucher);
        $activate = new ActivatePurchasedVoucher($this->vouchers);
        $activate->execute($voucher->id->value, VoucherFactory::now());

        try {
            $activate->execute($voucher->id->value, VoucherFactory::now()->modify('+1 day'));
            $this->fail('A voucher was activated twice.');
        } catch (VoucherAlreadyActiveException) {
            $this->assertSame(5, $this->vouchers->find($voucher->id)?->balance->value);
            $this->assertCount(1, $this->vouchers->movementsOf($voucher->id));
        }
    }

    /** A voucher that does not exist cannot be activated. */
    public function test_a_missing_voucher_cannot_be_activated(): void
    {
        $this->expectException(VoucherNotFoundException::class);

        new ActivatePurchasedVoucher($this->vouchers)->execute('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0b0c', VoucherFactory::now());
    }

    /** A customer reads their own voucher; another customer, or a person with no role there, reads a missing one (UP-06). */
    public function test_a_customer_reads_only_their_own_voucher(): void
    {
        $voucher = $this->start->execute(self::ANA, VoucherFactory::DEFINITION, VoucherFactory::now());
        $this->vouchers->save($voucher);
        $this->customers->ensure(self::LUIS);
        $get = new GetCustomerPurchasedVoucher($this->vouchers, $this->customers);

        $this->assertSame($voucher->id->value, $get->execute(self::ANA, $voucher->id->value)->id->value);

        foreach ([self::LUIS, '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0b03'] as $other) {
            try {
                $get->execute($other, $voucher->id->value);
                $this->fail('A voucher of another person was read.');
            } catch (VoucherNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(VoucherNotFoundException::class);
        $get->execute(self::ANA, (new PurchasedVoucherId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0b0d'))->value);
    }
}
