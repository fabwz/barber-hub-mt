<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Services\Domain;

use PHPUnit\Framework\TestCase;
use Src\Catalog\Services\Domain\Entities\Service;
use Src\Catalog\Services\Domain\ValueObjects\ServiceDuration;
use Src\Catalog\Services\Domain\ValueObjects\ServiceId;
use Src\Catalog\Services\Domain\ValueObjects\ServiceName;
use Src\Catalog\Services\Domain\ValueObjects\ServicePrice;

final class ServiceTest extends TestCase
{
    private const string SERVICE_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d22';

    /** A new service is active from the start with the data it was registered with (RF-03). */
    public function test_service_is_created_active(): void
    {
        $service = $this->service();

        $this->assertSame(self::SERVICE_ID, $service->id->value);
        $this->assertSame('Corte clásico', $service->name->value);
        $this->assertSame(30, $service->duration->minutes());
        $this->assertSame(7000, $service->price->colones());
        $this->assertTrue($service->active);
    }

    /** A stored service is rebuilt with the state it was saved with, inactive included. */
    public function test_service_can_be_reconstituted(): void
    {
        $service = Service::reconstitute(
            new ServiceId(self::SERVICE_ID),
            new ServiceName('Barba'),
            new ServiceDuration(15),
            new ServicePrice(3500),
            false,
        );

        $this->assertSame('Barba', $service->name->value);
        $this->assertSame(15, $service->duration->minutes());
        $this->assertSame(3500, $service->price->colones());
        $this->assertFalse($service->active);
    }

    /** Revising replaces the name, duration and price and leaves the state as it was. */
    public function test_service_is_revised(): void
    {
        $service = $this->service();

        $service->revise(new ServiceName('Corte moderno'), new ServiceDuration(45), new ServicePrice(7500));

        $this->assertSame(self::SERVICE_ID, $service->id->value);
        $this->assertSame('Corte moderno', $service->name->value);
        $this->assertSame(45, $service->duration->minutes());
        $this->assertSame(7500, $service->price->colones());
        $this->assertTrue($service->active);
    }

    /** A service is deactivated and activated again, never deleted (SV-04). */
    public function test_service_is_deactivated_and_reactivated(): void
    {
        $service = $this->service();

        $service->deactivate();
        $this->assertFalse($service->active);

        $service->activate();
        $this->assertTrue($service->active);
    }

    /** Build the classic cut of ₡7.000 and 30 minutes. */
    private function service(): Service
    {
        return Service::create(new ServiceId(self::SERVICE_ID), new ServiceName('Corte clásico'), new ServiceDuration(30), new ServicePrice(7000));
    }
}
