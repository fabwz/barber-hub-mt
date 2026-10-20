<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Services\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Catalog\Services\Application\DTOs\CreateServiceCommand;
use Src\Catalog\Services\Application\DTOs\UpdateServiceCommand;
use Src\Catalog\Services\Application\UseCases\CreateService;
use Src\Catalog\Services\Application\UseCases\ListPublicServices;
use Src\Catalog\Services\Application\UseCases\ListServices;
use Src\Catalog\Services\Application\UseCases\UpdateService;
use Src\Catalog\Services\Domain\Entities\Service;
use Src\Catalog\Services\Domain\Exceptions\ServiceNotFoundException;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Tests\Support\Catalog\InMemoryPublicServiceReader;
use Tests\Support\Catalog\InMemoryServiceRepository;

final class ManageServicesTest extends TestCase
{
    private InMemoryServiceRepository $services;

    private CreateService $createService;

    private UpdateService $updateService;

    /** Wire the use cases to the in-memory repository. */
    protected function setUp(): void
    {
        $this->services = new InMemoryServiceRepository;
        $this->createService = new CreateService($this->services);
        $this->updateService = new UpdateService($this->services);
    }

    /** A service is created active with its name, duration and price (RF-03). */
    public function test_service_is_created_active(): void
    {
        $service = $this->createService->execute(new CreateServiceCommand('  Corte clásico ', 30, 7000));

        $this->assertSame('Corte clásico', $service->name->value);
        $this->assertSame(30, $service->duration->minutes());
        $this->assertSame(7000, $service->price->colones());
        $this->assertTrue($service->active);
        $this->assertEquals($service, $this->services->find($service->id));
    }

    /**
     * Commands that break one value object each, and the translation key reported.
     *
     * @return array<string, array{string, int, int, string}>
     */
    public static function invalidCommands(): array
    {
        return [
            'seven minutes' => ['Corte', 7, 7000, 'services.duration_invalid'],
            'two hundred minutes' => ['Corte', 200, 7000, 'services.duration_invalid'],
            'zero colones' => ['Corte', 30, 0, 'services.price_invalid'],
            'blank name' => ['   ', 30, 7000, 'services.name_invalid'],
        ];
    }

    /** Invalid data is refused and nothing is stored (SV-01). */
    #[DataProvider('invalidCommands')]
    public function test_invalid_data_is_refused_and_nothing_is_stored(string $name, int $minutes, int $price, string $key): void
    {
        try {
            $this->createService->execute(new CreateServiceCommand($name, $minutes, $price));
            $this->fail('Invalid data was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame($key, $exception->translationKey());
            $this->assertSame([], $this->services->all());
        }
    }

    /** Raising the price changes the price and keeps the rest (RF-03). */
    public function test_price_is_raised_and_the_rest_is_kept(): void
    {
        $service = $this->createService->execute(new CreateServiceCommand('Corte clásico', 30, 7000));

        $updated = $this->updateService->execute(new UpdateServiceCommand($service->id->value, price: 7500));

        $this->assertSame(7500, $updated->price->colones());
        $this->assertSame('Corte clásico', $updated->name->value);
        $this->assertSame(30, $updated->duration->minutes());
        $this->assertTrue($updated->active);
        $this->assertEquals($updated, $this->services->find($service->id));
    }

    /** Every field can change at once. */
    public function test_every_field_can_change(): void
    {
        $service = $this->createService->execute(new CreateServiceCommand('Corte clásico', 30, 7000));

        $updated = $this->updateService->execute(new UpdateServiceCommand($service->id->value, 'Corte moderno', 45, 9000, false));

        $this->assertSame('Corte moderno', $updated->name->value);
        $this->assertSame(45, $updated->duration->minutes());
        $this->assertSame(9000, $updated->price->colones());
        $this->assertFalse($updated->active);
    }

    /** A service is deactivated and activated again, and stays stored the whole time (SV-04). */
    public function test_service_is_deactivated_and_reactivated(): void
    {
        $service = $this->createService->execute(new CreateServiceCommand('Corte clásico', 30, 7000));

        $this->assertFalse($this->updateService->execute(new UpdateServiceCommand($service->id->value, active: false))->active);
        $this->assertCount(1, $this->services->all());
        $this->assertTrue($this->updateService->execute(new UpdateServiceCommand($service->id->value, active: true))->active);
    }

    /** An invalid change is refused and the stored service stays as it was. */
    public function test_invalid_change_keeps_the_stored_service(): void
    {
        $service = $this->createService->execute(new CreateServiceCommand('Corte clásico', 30, 7000));

        try {
            $this->updateService->execute(new UpdateServiceCommand($service->id->value, durationMinutes: 7));
            $this->fail('A duration of 7 minutes was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame('services.duration_invalid', $exception->translationKey());
        }

        $this->assertSame(30, $this->services->find($service->id)?->duration->minutes());
    }

    /** Changing a service the barbershop does not have is a not-found error. */
    public function test_unknown_service_is_not_found(): void
    {
        try {
            $this->updateService->execute(new UpdateServiceCommand('0192f0c4-0000-7000-8000-000000000999', price: 7500));
            $this->fail('An unknown service was changed.');
        } catch (ServiceNotFoundException $exception) {
            $this->assertSame('services.not_found', $exception->translationKey());
        }
    }

    /** The owner lists every service, active or not, ordered by name. */
    public function test_owner_lists_active_and_inactive_services(): void
    {
        $barba = $this->createService->execute(new CreateServiceCommand('Barba', 15, 3500));
        $this->createService->execute(new CreateServiceCommand('Corte', 30, 7000));
        $this->updateService->execute(new UpdateServiceCommand($barba->id->value, active: false));

        $names = array_map(fn (Service $service): string => $service->name->value, new ListServices($this->services)->execute());

        $this->assertSame(['Barba', 'Corte'], $names);
    }

    /** A customer sees only the active services, each with its price split into base and tax (SV-04, RL-02). */
    public function test_customer_sees_only_the_active_services_with_their_breakdown(): void
    {
        $barba = $this->createService->execute(new CreateServiceCommand('Barba', 15, 3500));
        $this->createService->execute(new CreateServiceCommand('Corte clásico', 30, 7000));
        $this->updateService->execute(new UpdateServiceCommand($barba->id->value, active: false));

        $services = new ListPublicServices(new InMemoryPublicServiceReader($this->services))->execute();

        $this->assertCount(1, $services);
        $this->assertSame('Corte clásico', $services[0]->name);
        $this->assertSame(30, $services[0]->durationMinutes);
        $this->assertSame([7000, 6195, 805], [$services[0]->price, $services[0]->base, $services[0]->tax]);
    }
}
