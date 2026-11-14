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
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Vouchers\SeedsVouchers;
use Tests\Support\Vouchers\VoucherFactory;
use Tests\TestCase;

/** The adapter of the voucher definitions: what a barbershop sells and the services each voucher covers (BO-01, BO-06, MT-03). */
final class EloquentVoucherDefinitionRepositoryTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;
    use SeedsVouchers;

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

    /** The list brings the definitions of the barbershop by name, withdrawn ones too, each with its services, and none of another barbershop. */
    public function test_all_lists_the_definitions_of_the_barbershop_by_name_with_their_services(): void
    {
        $classic = $this->insertService($this->puraNavaja, 'Corte clásico', 30, 6000);
        $this->definitions->save(VoucherFactory::definition(
            name: 'Bono B', services: CoveredServices::of([new ServiceId($classic)]), id: $this->definitions->nextId()->value, barbershop: $this->puraNavaja,
        ));
        $withdrawn = VoucherFactory::definition(
            name: 'Bono A', services: CoveredServices::of([new ServiceId($this->degradado), new ServiceId($classic)]), id: $this->definitions->nextId()->value, barbershop: $this->puraNavaja,
        );
        $withdrawn->withdraw(VoucherFactory::now()->modify('+1 day'));
        $this->definitions->save($withdrawn);
        $this->insertVoucherDefinition($this->fadeLab, []);
        $this->workAs($this->puraNavaja);

        $all = $this->definitions->all();

        $this->assertSame(['Bono A', 'Bono B'], array_map(fn (VoucherDefinition $definition): string => $definition->name->value, $all));
        $this->assertSame([false, true], array_map(fn (VoucherDefinition $definition): bool => $definition->isActive, $all));
        $this->assertEqualsCanonicalizing([$this->degradado, $classic], array_map(fn (ServiceId $service): string => $service->value, $all[0]->services->ids));
        $this->assertSame([$classic], array_map(fn (ServiceId $service): string => $service->value, $all[1]->services->ids));
    }

    /** A definition has purchases once a customer bought it, paid or not, and only the ones of its own barbershop count. */
    public function test_has_purchases_tells_whether_a_customer_bought_the_voucher(): void
    {
        $sold = $this->definition([$this->degradado]);
        $unsold = $this->definition([$this->degradado]);
        $this->definitions->save($sold);
        $this->definitions->save($unsold);
        [, $membership] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');

        $this->assertFalse($this->definitions->hasPurchases($sold->id));

        $this->insertPurchasedVoucher($this->puraNavaja, $membership, $sold->id->value, 'pending');

        $this->assertTrue($this->definitions->hasPurchases($sold->id));
        $this->assertFalse($this->definitions->hasPurchases($unsold->id));

        $this->workAs($this->fadeLab);

        $this->assertFalse($this->definitions->hasPurchases($sold->id));
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
