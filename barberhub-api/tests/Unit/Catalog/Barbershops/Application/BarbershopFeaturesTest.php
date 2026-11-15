<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Barbershops\Application;

use PHPUnit\Framework\TestCase;
use Src\Catalog\Barbershops\Application\DTOs\UpdateBarbershopFeaturesCommand;
use Src\Catalog\Barbershops\Application\UseCases\GetBarbershopFeatures;
use Src\Catalog\Barbershops\Application\UseCases\UpdateBarbershopFeatures;
use Src\Catalog\Barbershops\Domain\Entities\Barbershop;
use Src\Catalog\Barbershops\Domain\Exceptions\BarbershopNotFoundException;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopFeatures;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentPolicy;
use Src\Catalog\Barbershops\Domain\ValueObjects\PublicLink;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Phone;
use Tests\Support\Catalog\InMemoryBarbershopRepository;
use Tests\Support\Shared\FixedBarbershopContext;

/** The owner reads and changes the switches of the vouchers and of the waitlist (RF-09). */
final class BarbershopFeaturesTest extends TestCase
{
    private const string BARBERSHOP = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d22';

    private InMemoryBarbershopRepository $barbershops;

    private GetBarbershopFeatures $get;

    private UpdateBarbershopFeatures $update;

    /** A barbershop that was just registered, as the current one. */
    protected function setUp(): void
    {
        $this->barbershops = new InMemoryBarbershopRepository;
        $this->barbershops->save(Barbershop::create(new BarbershopId(self::BARBERSHOP), new BarbershopName('Pura Navaja'), new City('San Carlos'), new Phone('2460-0000'), new PublicLink('pura-navaja')));
        $context = new FixedBarbershopContext(new BarbershopId(self::BARBERSHOP));
        $this->get = new GetBarbershopFeatures($this->barbershops, $context);
        $this->update = new UpdateBarbershopFeatures($this->barbershops, $context);
    }

    /** A new barbershop has the vouchers and the waitlist off. */
    public function test_a_new_barbershop_has_both_switches_off(): void
    {
        $features = $this->get->execute();

        $this->assertFalse($features->vouchersEnabled);
        $this->assertFalse($features->waitlistEnabled);
    }

    /** A barbershop rebuilt without features has them off. */
    public function test_a_barbershop_stored_without_features_has_them_off(): void
    {
        $barbershop = Barbershop::reconstitute(new BarbershopId(self::BARBERSHOP), new BarbershopName('Pura Navaja'), new City('San Carlos'), new Phone('2460-0000'), new PublicLink('pura-navaja'), PaymentPolicy::initial());

        $this->assertEquals(BarbershopFeatures::initial(), $barbershop->features);
    }

    /** Each switch changes alone and the other keeps its value. */
    public function test_a_field_that_does_not_come_keeps_its_value(): void
    {
        $this->update->execute(new UpdateBarbershopFeaturesCommand(true, null));
        $afterVouchers = $this->get->execute();
        $this->update->execute(new UpdateBarbershopFeaturesCommand(null, true));
        $afterWaitlist = $this->get->execute();
        $this->update->execute(new UpdateBarbershopFeaturesCommand(false, null));

        $this->assertSame([true, false], [$afterVouchers->vouchersEnabled, $afterVouchers->waitlistEnabled]);
        $this->assertSame([true, true], [$afterWaitlist->vouchersEnabled, $afterWaitlist->waitlistEnabled]);
        $this->assertSame([false, true], [$this->get->execute()->vouchersEnabled, $this->get->execute()->waitlistEnabled]);
    }

    /** Changing the switches leaves the rest of the barbershop as it was. */
    public function test_the_rest_of_the_barbershop_is_untouched(): void
    {
        $before = $this->barbershops->find(new BarbershopId(self::BARBERSHOP));

        $this->update->execute(new UpdateBarbershopFeaturesCommand(true, true));

        $after = $this->barbershops->find(new BarbershopId(self::BARBERSHOP));
        $this->assertEquals([$before?->name, $before?->city, $before?->phone, $before?->link, $before?->paymentPolicy, $before?->profile], [$after?->name, $after?->city, $after?->phone, $after?->link, $after?->paymentPolicy, $after?->profile]);
    }

    /** A barbershop that does not exist cannot be read or changed. */
    public function test_a_missing_barbershop_is_not_found(): void
    {
        $get = new GetBarbershopFeatures($this->barbershops, new FixedBarbershopContext(new BarbershopId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d99')));

        $this->expectException(BarbershopNotFoundException::class);

        $get->execute();
    }
}
