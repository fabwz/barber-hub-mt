<?php

declare(strict_types=1);

namespace Tests\Feature\Vouchers\VoucherDefinitions;

use Illuminate\Support\Facades\DB;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Vouchers\VoucherDefinitions\Domain\Contracts\VoucherDefinitionRepository;
use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\CoveredServices;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\ServiceId;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Vouchers\VoucherFactory;
use Tests\TestCase;

/** The adapter of the voucher definitions: what a barbershop sells and the services each voucher covers (BO-01, BO-06, MT-03). */
final class EloquentVoucherDefinitionRepositoryTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;

    private VoucherDefinitionRepository $definitions;

    /** Pura Navaja has the services "Degradado" and a second one; the repository works as that barbershop. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->workAs($this->puraNavaja);
    }

    /** "Bono 5 cortes" comes back with its numbers, its services and on sale. */
    public function test_a_definition_is_saved_and_read_back(): void
    {
        $classic = $this->insertService($this->puraNavaja, 'Corte clásico', 30, 6000);
        $definition = $this->definition([$this->degradado, $classic]);

        $this->definitions->save($definition);
        $found = $this->definitions->find($definition->id);

        $this->assertNotNull($found);
        $this->assertSame(['Bono 5 cortes', 5, 30000, 90, true], [$found->name->value, $found->sessionsCount->value, $found->price->colones, $found->validityDays->value, $found->isActive]);
        $this->assertEqualsCanonicalizing([$this->degradado, $classic], array_map(fn (ServiceId $service): string => $service->value, $found->services->ids));
        $this->assertTrue($found->covers(new ServiceId($classic)));
        $this->assertEquals($definition->createdAt, $found->createdAt);
    }

    /** Changing the covered services leaves exactly the new ones, and withdrawing the voucher is kept. */
    public function test_the_services_and_the_withdrawal_are_updated(): void
    {
        $classic = $this->insertService($this->puraNavaja, 'Corte clásico', 30, 6000);
        $definition = $this->definition([$this->degradado, $classic]);
        $this->definitions->save($definition);

        $changed = VoucherDefinition::reconstitute(
            $definition->id, $definition->barbershopId, $definition->name, $definition->sessionsCount, $definition->price, $definition->validityDays,
            CoveredServices::of([new ServiceId($classic)]), true, $definition->createdAt, $definition->updatedAt,
        );
        $changed->withdraw(VoucherFactory::now()->modify('+1 day'));
        $this->definitions->save($changed);

        $found = $this->definitions->find($definition->id);
        $this->assertNotNull($found);
        $this->assertFalse($found->isActive);
        $this->assertSame([$classic], array_map(fn (ServiceId $service): string => $service->value, $found->services->ids));
        $this->assertSame(1, DB::table('voucher_definition_services')->where('voucher_definition_id', $definition->id->value)->count());
    }

    /** A definition of another barbershop is not found, and a service of another one cannot be covered (MT-03, MT-04). */
    public function test_another_barbershop_cannot_reach_the_definition(): void
    {
        $definition = $this->definition([$this->degradado]);
        $this->definitions->save($definition);

        $this->workAs($this->fadeLab);

        $this->assertNull($this->definitions->find($definition->id));
    }

    /**
     * @param  list<string>  $services
     */
    private function definition(array $services): VoucherDefinition
    {
        return VoucherFactory::definition(
            services: CoveredServices::of(array_map(fn (string $service): ServiceId => new ServiceId($service), $services)),
            id: (string) $this->definitions->nextId()->value,
            barbershop: $this->puraNavaja,
        );
    }

    /** Make the repository, and the queries of the test, work as a barbershop. */
    private function workAs(string $barbershopId): void
    {
        $this->app->instance(BarbershopContext::class, new FixedBarbershopContext(new BarbershopId($barbershopId)));
        $this->scopeQueriesTo($barbershopId);
        $this->definitions = $this->app->make(VoucherDefinitionRepository::class);
    }
}
