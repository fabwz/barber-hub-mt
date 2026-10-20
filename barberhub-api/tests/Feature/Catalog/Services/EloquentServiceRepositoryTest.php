<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Catalog\Services\Application\Contracts\PublicServiceReader;
use Src\Catalog\Services\Domain\Entities\Service;
use Src\Catalog\Services\Domain\ValueObjects\ServiceDuration;
use Src\Catalog\Services\Domain\ValueObjects\ServiceId;
use Src\Catalog\Services\Domain\ValueObjects\ServiceName;
use Src\Catalog\Services\Domain\ValueObjects\ServicePrice;
use Src\Catalog\Services\Infrastructure\Persistence\Models\ServiceModel;
use Src\Catalog\Services\Infrastructure\Persistence\Queries\EloquentPublicServiceReader;
use Src\Catalog\Services\Infrastructure\Persistence\Repositories\EloquentServiceRepository;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\Support\Tenancy\TenantResources;
use Tests\TestCase;

final class EloquentServiceRepositoryTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private const string PURA_NAVAJA = '0192f0c4-0000-7000-8000-00000000b001';

    private const string FADE_LAB = '0192f0c4-0000-7000-8000-00000000b002';

    private EloquentServiceRepository $repository;

    /** Two barbershops; the repository works for the first and the queries of the test reach it. */
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::PURA_NAVAJA => 'pura-navaja', self::FADE_LAB => 'fade-lab'] as $id => $link) {
            DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);
        }

        $this->repository = $this->repositoryOf(self::PURA_NAVAJA);
        $this->scopeQueriesTo(self::PURA_NAVAJA);
    }

    /** A saved service keeps its barbershop, name, duration, price and state. */
    public function test_service_is_saved_in_the_current_barbershop(): void
    {
        $service = $this->service('Corte clásico');

        $this->repository->save($service);

        $model = ServiceModel::query()->findOrFail($service->id->value);
        $this->assertSame(self::PURA_NAVAJA, $model->barbershop_id);
        $this->assertSame('Corte clásico', $model->name);
        $this->assertSame(30, $model->duration_minutes);
        $this->assertSame(7000, $model->price);
        $this->assertTrue($model->is_active);
    }

    /** Saving a service again updates it instead of adding another row, and the state is stored too. */
    public function test_saving_the_same_service_again_updates_it(): void
    {
        $service = $this->service('Corte clásico');
        $this->repository->save($service);

        $service->revise(new ServiceName('Corte moderno'), new ServiceDuration(45), new ServicePrice(7500));
        $service->deactivate();
        $this->repository->save($service);

        $this->assertSame(1, ServiceModel::query()->count());
        $model = ServiceModel::query()->findOrFail($service->id->value);
        $this->assertSame(['Corte moderno', 45, 7500, false], [$model->name, $model->duration_minutes, $model->price, $model->is_active]);
    }

    /** A saved service is read back as the same entity, and an unknown one is not found. */
    public function test_saved_service_is_found(): void
    {
        $service = $this->service('Corte clásico');
        $this->repository->save($service);

        $this->assertEquals($service, $this->repository->find($service->id));
        $this->assertNull($this->repository->find(new ServiceId((string) Str::uuid7())));
    }

    /** Two services may share a name: the requirements do not ask for it to be unique. */
    public function test_two_services_may_share_a_name(): void
    {
        $this->repository->save($this->service('Corte'));
        $this->repository->save($this->service('Corte'));

        $this->assertCount(2, $this->repository->all());
    }

    /** The listing brings every service, active or not, ordered by name. */
    public function test_all_lists_active_and_inactive_services_ordered_by_name(): void
    {
        $inactive = $this->service('Barba');
        $inactive->deactivate();
        $this->repository->save($this->service('Corte'));
        $this->repository->save($inactive);
        $this->repository->save($this->service('Afeitado'));

        $names = array_map(fn (Service $service): string => $service->name->value, $this->repository->all());

        $this->assertSame(['Afeitado', 'Barba', 'Corte'], $names);
    }

    /** The repository of one barbershop neither finds nor lists the services of another (MT-04). */
    public function test_repository_does_not_reach_the_services_of_another_barbershop(): void
    {
        $other = $this->service('Corte de Fade Lab');
        $this->saveFor(self::FADE_LAB, $other);
        $own = $this->service('Corte de Pura Navaja');
        $this->repository->save($own);

        $this->assertNull($this->repository->find($other->id));
        $this->assertEquals([$own], $this->repository->all());

        $this->scopeQueriesTo(self::FADE_LAB);
        $this->assertEquals([$other], $this->repositoryOf(self::FADE_LAB)->all());
    }

    /**
     * The filter of the repository is a barrier of its own: with the database scoped to Fade Lab, whose rows the policy
     * lets through, the repository of Pura Navaja still reaches none of them.
     */
    public function test_repository_filters_by_the_context_even_when_the_policy_lets_the_rows_through(): void
    {
        $other = $this->service('Corte de Fade Lab');
        $this->saveFor(self::FADE_LAB, $other);
        $this->scopeQueriesTo(self::FADE_LAB);

        $this->assertSame(1, DB::table('services')->count());
        $this->assertNull($this->repository->find($other->id));
        $this->assertSame([], $this->repository->all());
        $this->assertSame([], new EloquentPublicServiceReader(new FixedBarbershopContext(new BarbershopId(self::PURA_NAVAJA)))->activeServices());
    }

    /** Saving a service with the identity of one of another barbershop does not touch that row: the primary key refuses it. */
    public function test_saving_never_changes_a_service_of_another_barbershop(): void
    {
        $other = $this->service('Corte de Fade Lab');
        $this->saveFor(self::FADE_LAB, $other);

        $clash = Service::reconstitute($other->id, new ServiceName('Robado'), new ServiceDuration(15), new ServicePrice(1), false);

        try {
            DB::transaction(fn () => $this->repository->save($clash));
            $this->fail('A service with the identity of one of another barbershop was saved.');
        } catch (QueryException) {
            $this->assertSame(0, DB::table('services')->count());
        }

        $this->scopeQueriesTo(self::FADE_LAB);
        $this->assertEquals($other, $this->repositoryOf(self::FADE_LAB)->find($other->id));
    }

    /** Every new identity is a distinct time-ordered UUID. */
    public function test_next_id_returns_distinct_uuid_v7_values(): void
    {
        $first = $this->repository->nextId()->value;
        $second = $this->repository->nextId()->value;

        $this->assertTrue(Str::isUuid($first, 7));
        $this->assertNotSame($first, $second);
    }

    /** The public reader brings the active services of the current barbershop with the breakdown of the price (SV-04, RL-02). */
    public function test_public_reader_brings_only_the_active_services_of_the_barbershop(): void
    {
        $inactive = $this->service('Barba');
        $inactive->deactivate();
        $this->repository->save($inactive);
        $this->repository->save($this->service('Corte clásico'));
        $this->saveFor(self::FADE_LAB, $this->service('Corte de Fade Lab'));
        $this->scopeQueriesTo(self::PURA_NAVAJA);

        $services = new EloquentPublicServiceReader(new FixedBarbershopContext(new BarbershopId(self::PURA_NAVAJA)))->activeServices();

        $this->assertCount(1, $services);
        $this->assertSame('Corte clásico', $services[0]->name);
        $this->assertSame([7000, 6195, 805], [$services[0]->price, $services[0]->base, $services[0]->tax]);
    }

    /** The port of the public list is bound to its adapter, and the registry of the tenancy suite knows the table. */
    public function test_port_is_bound_and_the_table_is_registered(): void
    {
        $this->assertInstanceOf(EloquentPublicServiceReader::class, $this->app->make(PublicServiceReader::class));
        $this->assertContains('services', array_map(fn ($resource): string => $resource->table, TenantResources::all()));
    }

    /** Build a new active service of 30 minutes and ₡7.000. */
    private function service(string $name): Service
    {
        return Service::create($this->repository->nextId(), new ServiceName($name), new ServiceDuration(30), new ServicePrice(7000));
    }

    /** Repository working for a barbershop. */
    private function repositoryOf(string $barbershopId): EloquentServiceRepository
    {
        return new EloquentServiceRepository(new FixedBarbershopContext(new BarbershopId($barbershopId)));
    }

    /** Save a service as the barbershop would, leaving the queries scoped to the first one again. */
    private function saveFor(string $barbershopId, Service $service): void
    {
        $this->scopeQueriesTo($barbershopId);
        $this->repositoryOf($barbershopId)->save($service);
        $this->scopeQueriesTo(self::PURA_NAVAJA);
    }
}
