<?php

declare(strict_types=1);

namespace Tests\Unit\Vouchers\VoucherDefinitions\Domain;

use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Vouchers\Shared\Domain\ValueObjects\SessionCount;
use Src\Vouchers\Shared\Domain\ValueObjects\ValidityDays;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherName;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\CoveredServices;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\ServiceId;
use Tests\Support\Vouchers\VoucherFactory;

/** What the owner changes in a voucher on sale: name and price, the terms, and the sale flag (RF-26). */
final class VoucherDefinitionChangesTest extends TestCase
{
    /** Revising the name and the price keeps the rest and stamps the moment of the change. */
    public function test_revising_changes_name_and_price_only(): void
    {
        $definition = VoucherFactory::definition();
        $later = VoucherFactory::now()->modify('+2 days');

        $definition->revise(VoucherName::of('Bono 5 cortes 2031'), Money::of(36000), $later);

        $this->assertSame(['Bono 5 cortes 2031', 36000, 5, 90], [$definition->name->value, $definition->price->colones, $definition->sessionsCount->value, $definition->validityDays->value]);
        $this->assertEquals($later, $definition->updatedAt);
        $this->assertEquals(VoucherFactory::now(), $definition->createdAt);
    }

    /** A price of zero is refused when revising, as when creating. */
    public function test_revising_to_a_price_of_zero_is_refused(): void
    {
        $definition = VoucherFactory::definition();

        $this->expectException(InvalidValueException::class);

        $definition->revise($definition->name, Money::zero(), VoucherFactory::now());
    }

    /** Revising with the same name and price is not a change. */
    public function test_revising_with_the_same_values_does_not_move_the_update_date(): void
    {
        $definition = VoucherFactory::definition();

        $definition->revise(VoucherName::of('Bono 5 cortes'), Money::of(30000), VoucherFactory::now()->modify('+1 day'));

        $this->assertEquals(VoucherFactory::now(), $definition->updatedAt);
    }

    /** Reconfiguring replaces the sessions, the validity and the services; the same terms in another order are not a change. */
    public function test_reconfiguring_replaces_the_terms(): void
    {
        $definition = VoucherFactory::definition();
        $later = VoucherFactory::now()->modify('+1 day');

        $definition->reconfigure(SessionCount::of(5), ValidityDays::of(90), CoveredServices::of([new ServiceId(VoucherFactory::FADE), new ServiceId(VoucherFactory::CLASSIC_CUT)]), $later);
        $this->assertEquals(VoucherFactory::now(), $definition->updatedAt);

        $definition->reconfigure(SessionCount::of(10), ValidityDays::of(120), CoveredServices::of([new ServiceId(VoucherFactory::FADE)]), $later);

        $this->assertSame([10, 120], [$definition->sessionsCount->value, $definition->validityDays->value]);
        $this->assertTrue($definition->covers(new ServiceId(VoucherFactory::FADE)));
        $this->assertFalse($definition->covers(new ServiceId(VoucherFactory::CLASSIC_CUT)));
        $this->assertEquals($later, $definition->updatedAt);
    }

    /** Two sets of services are the same when they hold the same services, whatever their order. */
    public function test_services_are_compared_as_sets(): void
    {
        $both = CoveredServices::of([new ServiceId(VoucherFactory::CLASSIC_CUT), new ServiceId(VoucherFactory::FADE)]);

        $this->assertTrue($both->sameAs(CoveredServices::of([new ServiceId(VoucherFactory::FADE), new ServiceId(VoucherFactory::CLASSIC_CUT)])));
        $this->assertFalse($both->sameAs(CoveredServices::of([new ServiceId(VoucherFactory::FADE)])));
        $this->assertFalse($both->sameAs(CoveredServices::of([new ServiceId(VoucherFactory::FADE), new ServiceId(VoucherFactory::RESERVATION)])));
    }

    /** A withdrawn voucher goes back on sale, and putting back one that is on sale changes nothing. */
    public function test_a_withdrawn_voucher_goes_back_on_sale(): void
    {
        $definition = VoucherFactory::definition();
        $withdrawnAt = VoucherFactory::now()->modify('+1 day');

        $definition->reactivate($withdrawnAt);
        $this->assertEquals(VoucherFactory::now(), $definition->updatedAt);

        $definition->withdraw($withdrawnAt);
        $this->assertFalse($definition->isActive);

        $definition->reactivate($withdrawnAt->modify('+1 day'));
        $this->assertTrue($definition->isActive);
        $this->assertEquals($withdrawnAt->modify('+1 day'), $definition->updatedAt);
    }
}
