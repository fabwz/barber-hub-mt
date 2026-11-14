<?php

declare(strict_types=1);

namespace Tests\Unit\Vouchers\VoucherDefinitions\Domain;

use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\CoveredServices;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\ServiceId;
use Tests\Support\Vouchers\VoucherFactory;

/** The voucher a barbershop sells (BO-01, BO-06), without a database. */
final class VoucherDefinitionTest extends TestCase
{
    /** "Bono 5 cortes" covers only the classic cut and the fade, and is on sale when it is created (RF-26). */
    public function test_a_new_definition_is_on_sale_and_covers_only_its_services(): void
    {
        $definition = VoucherFactory::definition();

        $this->assertTrue($definition->isActive);
        $this->assertTrue($definition->covers(new ServiceId(VoucherFactory::CLASSIC_CUT)));
        $this->assertTrue($definition->covers(new ServiceId(VoucherFactory::FADE)));
        $this->assertFalse($definition->covers(new ServiceId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d01')));
    }

    /** A voucher that covers no service is rejected. */
    public function test_a_voucher_needs_at_least_one_service(): void
    {
        $this->expectException(InvalidValueException::class);

        CoveredServices::of([]);
    }

    /** A service listed twice is covered once. */
    public function test_a_repeated_service_counts_once(): void
    {
        $services = CoveredServices::of([new ServiceId(VoucherFactory::FADE), new ServiceId(VoucherFactory::FADE)]);

        $this->assertCount(1, $services->ids);
    }

    /** A voucher with a price of zero is rejected. */
    public function test_a_definition_needs_a_positive_price(): void
    {
        $this->expectException(InvalidValueException::class);

        VoucherFactory::definition(price: 0);
    }

    /** Withdrawing takes it off sale, once; the date of the change is the first one. */
    public function test_withdrawing_takes_it_off_sale(): void
    {
        $definition = VoucherFactory::definition();
        $at = VoucherFactory::now()->modify('+1 day');

        $definition->withdraw($at);
        $definition->withdraw($at->modify('+1 day'));

        $this->assertFalse($definition->isActive);
        $this->assertEquals($at, $definition->updatedAt);
    }
}
