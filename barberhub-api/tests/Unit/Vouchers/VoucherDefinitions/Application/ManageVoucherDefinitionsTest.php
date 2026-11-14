<?php

declare(strict_types=1);

namespace Tests\Unit\Vouchers\VoucherDefinitions\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Vouchers\VoucherDefinitions\Application\DTOs\CreateVoucherDefinitionCommand;
use Src\Vouchers\VoucherDefinitions\Application\DTOs\UpdateVoucherDefinitionCommand;
use Src\Vouchers\VoucherDefinitions\Application\ResolveCoveredServices;
use Src\Vouchers\VoucherDefinitions\Application\UseCases\CreateVoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Application\UseCases\GetVoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Application\UseCases\ListVoucherDefinitions;
use Src\Vouchers\VoucherDefinitions\Application\UseCases\UpdateVoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Domain\Exceptions\UnknownCoveredServiceException;
use Src\Vouchers\VoucherDefinitions\Domain\Exceptions\VoucherDefinitionHasSalesException;
use Src\Vouchers\VoucherDefinitions\Domain\Exceptions\VoucherDefinitionNotFoundException;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\MutableClock;
use Tests\Support\Vouchers\InMemoryCatalogServices;
use Tests\Support\Vouchers\InMemoryVoucherDefinitionRepository;
use Tests\Support\Vouchers\VoucherFactory;

/** The use cases of the owner over the vouchers of the barbershop (RF-26, BO-01). */
final class ManageVoucherDefinitionsTest extends TestCase
{
    private InMemoryVoucherDefinitionRepository $definitions;

    private InMemoryCatalogServices $catalog;

    private MutableClock $clock;

    private CreateVoucherDefinition $create;

    private UpdateVoucherDefinition $update;

    /** A barbershop whose catalog has the classic cut and the fade, and a clock at the fixed moment. */
    protected function setUp(): void
    {
        $this->definitions = new InMemoryVoucherDefinitionRepository;
        $this->catalog = new InMemoryCatalogServices;
        $this->catalog->add(VoucherFactory::CLASSIC_CUT);
        $this->catalog->add(VoucherFactory::FADE);
        $this->clock = new MutableClock(VoucherFactory::now());
        $coveredServices = new ResolveCoveredServices($this->catalog);
        $this->create = new CreateVoucherDefinition($this->definitions, $coveredServices, new FixedBarbershopContext(new BarbershopId(VoucherFactory::BARBERSHOP)), $this->clock);
        $this->update = new UpdateVoucherDefinition($this->definitions, $coveredServices, $this->clock);
    }

    /** "Bono 5 cortes" is created on sale for the barbershop and covers exactly the classic cut and the fade. */
    public function test_a_voucher_is_created_on_sale_with_exactly_its_services(): void
    {
        $definition = $this->create->execute($this->command());

        $this->assertSame(['Bono 5 cortes', 5, 30000, 90, true, VoucherFactory::BARBERSHOP], [
            $definition->name->value, $definition->sessionsCount->value, $definition->price->colones, $definition->validityDays->value, $definition->isActive, $definition->barbershopId->value,
        ]);
        $this->assertEqualsCanonicalizing([VoucherFactory::CLASSIC_CUT, VoucherFactory::FADE], array_map(fn ($service): string => $service->value, $definition->services->ids));
        $this->assertSame($definition, $this->definitions->find($definition->id));
    }

    /**
     * The sessions out of range.
     *
     * @return array<string, array{int}>
     */
    public static function invalidSessions(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'above the column' => [32768]];
    }

    /** A voucher of no sessions, or beyond what the column holds, is rejected and nothing is stored (BO-01). */
    #[DataProvider('invalidSessions')]
    public function test_a_voucher_without_sessions_is_rejected(int $sessions): void
    {
        $this->expectException(InvalidValueException::class);

        try {
            $this->create->execute($this->command(sessions: $sessions));
        } finally {
            $this->assertSame([], $this->definitions->all());
        }
    }

    /** A voucher that covers no service is rejected (BO-01). */
    public function test_a_voucher_without_services_is_rejected(): void
    {
        $this->expectException(InvalidValueException::class);

        $this->create->execute($this->command(services: []));
    }

    /** A price of zero and a validity of zero days are rejected. */
    public function test_a_price_or_a_validity_of_zero_is_rejected(): void
    {
        foreach ([$this->command(price: 0), $this->command(validityDays: 0)] as $command) {
            try {
                $this->create->execute($command);
                $this->fail('A voucher with a zero price or validity must be rejected.');
            } catch (InvalidValueException) {
                $this->assertSame([], $this->definitions->all());
            }
        }
    }

    /** A service the barbershop does not have is rejected by its position, and nothing is stored. */
    public function test_a_service_of_another_barbershop_is_rejected_by_position(): void
    {
        try {
            $this->create->execute($this->command(services: [VoucherFactory::CLASSIC_CUT, '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0aff']));
            $this->fail('A service that is not in the catalog must be rejected.');
        } catch (UnknownCoveredServiceException $exception) {
            $this->assertSame(1, $exception->index());
            $this->assertSame([], $this->definitions->all());
        }
    }

    /** Without sales everything can be changed: the numbers, the services and the sale flag. */
    public function test_a_voucher_without_sales_changes_freely(): void
    {
        $id = $this->create->execute($this->command())->id->value;
        $this->clock->moveTo(VoucherFactory::now()->modify('+1 day'));

        $changed = $this->update->execute(new UpdateVoucherDefinitionCommand($id, 'Bono 10 cortes', 10, 55000, 120, [VoucherFactory::CLASSIC_CUT], false));

        $this->assertSame(['Bono 10 cortes', 10, 55000, 120, false], [$changed->name->value, $changed->sessionsCount->value, $changed->price->colones, $changed->validityDays->value, $changed->isActive]);
        $this->assertSame([VoucherFactory::CLASSIC_CUT], array_map(fn ($service): string => $service->value, $changed->services->ids));
        $this->assertEquals(VoucherFactory::now()->modify('+1 day'), $changed->updatedAt);
    }

    /** With sales the sessions, the validity and the services stay: another value is a conflict and nothing changes (RF-26). */
    public function test_a_sold_voucher_keeps_its_sessions_validity_and_services(): void
    {
        $definition = $this->create->execute($this->command());
        $this->definitions->markSold($definition->id);

        foreach ([
            new UpdateVoucherDefinitionCommand($definition->id->value, sessionsCount: 6),
            new UpdateVoucherDefinitionCommand($definition->id->value, validityDays: 30),
            new UpdateVoucherDefinitionCommand($definition->id->value, serviceIds: [VoucherFactory::CLASSIC_CUT]),
            new UpdateVoucherDefinitionCommand($definition->id->value, name: 'Otro nombre', price: 1000, sessionsCount: 6),
        ] as $command) {
            try {
                $this->update->execute($command);
                $this->fail('A sold voucher must not change its terms.');
            } catch (VoucherDefinitionHasSalesException $exception) {
                $this->assertSame('bono-con-ventas', $exception->rule());
            }
        }

        $stored = $this->definitions->find($definition->id);
        $this->assertSame(['Bono 5 cortes', 5, 30000, 90], [$stored?->name->value, $stored?->sessionsCount->value, $stored?->price->colones, $stored?->validityDays->value]);
    }

    /** With sales the name, the price and the sale flag still change, and repeating the same terms is not a change. */
    public function test_a_sold_voucher_still_changes_name_price_and_sale_flag(): void
    {
        $definition = $this->create->execute($this->command());
        $this->definitions->markSold($definition->id);

        $changed = $this->update->execute(new UpdateVoucherDefinitionCommand(
            $definition->id->value, 'Bono cinco cortes', 5, 32000, 90, [VoucherFactory::FADE, VoucherFactory::CLASSIC_CUT], false,
        ));

        $this->assertSame(['Bono cinco cortes', 5, 32000, 90, false], [$changed->name->value, $changed->sessionsCount->value, $changed->price->colones, $changed->validityDays->value, $changed->isActive]);
        $this->assertTrue($this->update->execute(new UpdateVoucherDefinitionCommand($definition->id->value, active: true))->isActive);
    }

    /** An unknown service on an update is rejected before anything changes. */
    public function test_an_update_with_an_unknown_service_changes_nothing(): void
    {
        $definition = $this->create->execute($this->command());

        $this->expectException(UnknownCoveredServiceException::class);

        try {
            $this->update->execute(new UpdateVoucherDefinitionCommand($definition->id->value, name: 'Nuevo', serviceIds: ['0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0aff']));
        } finally {
            $this->assertSame('Bono 5 cortes', $this->definitions->find($definition->id)?->name->value);
        }
    }

    /** A voucher that is not in the barbershop is not found, to update and to read. */
    public function test_an_unknown_voucher_is_not_found_to_update(): void
    {
        $this->expectException(VoucherDefinitionNotFoundException::class);

        $this->update->execute(new UpdateVoucherDefinitionCommand('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0aee', name: 'Nuevo'));
    }

    /** A voucher that is not in the barbershop is not found to read either. */
    public function test_an_unknown_voucher_is_not_found_to_read(): void
    {
        $this->expectException(VoucherDefinitionNotFoundException::class);

        new GetVoucherDefinition($this->definitions)->execute('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0aee');
    }

    /** The list brings the vouchers on sale and the withdrawn ones, by name. */
    public function test_the_list_brings_withdrawn_vouchers_too(): void
    {
        $this->create->execute($this->command(name: 'Bono B'));
        $withdrawn = $this->create->execute($this->command(name: 'Bono A'));
        $this->update->execute(new UpdateVoucherDefinitionCommand($withdrawn->id->value, active: false));

        $names = array_map(fn ($definition): string => $definition->name->value.($definition->isActive ? '' : ' (retirado)'), new ListVoucherDefinitions($this->definitions)->execute());

        $this->assertSame(['Bono A (retirado)', 'Bono B'], $names);
    }

    /**
     * A command for "Bono 5 cortes" unless told otherwise.
     *
     * @param  list<string>|null  $services
     */
    private function command(string $name = 'Bono 5 cortes', int $sessions = 5, int $price = 30000, int $validityDays = 90, ?array $services = null): CreateVoucherDefinitionCommand
    {
        return new CreateVoucherDefinitionCommand($name, $sessions, $price, $validityDays, $services ?? [VoucherFactory::CLASSIC_CUT, VoucherFactory::FADE]);
    }
}
