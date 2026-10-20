<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Barbershops;

use Src\Catalog\Barbershops\Domain\Entities\Barbershop;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Models\BarbershopModel;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Repositories\EloquentBarbershopRepository;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Phone;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class EloquentBarbershopRepositoryTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private EloquentBarbershopRepository $repository;

    /** Create the repository every scenario works on. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentBarbershopRepository;
    }

    /** A saved barbershop keeps its name, city and phone. */
    public function test_barbershop_is_saved(): void
    {
        $barbershop = $this->barbershop('Barbería Pura Navaja');

        $this->repository->save($barbershop);

        $model = BarbershopModel::query()->findOrFail($barbershop->id->value);
        $this->assertSame('Barbería Pura Navaja', $model->name);
        $this->assertSame('San Carlos', $model->city);
        $this->assertSame('2460-0000', $model->phone);
    }

    /** Saving a barbershop again updates its row instead of adding another. */
    public function test_saving_the_same_barbershop_again_updates_it(): void
    {
        $id = $this->repository->nextId();
        $this->repository->save(Barbershop::create($id, new BarbershopName('Pura Navaja'), new City('San Carlos'), new Phone('2460-0000')));

        $this->repository->save(Barbershop::reconstitute($id, new BarbershopName('La Navaja'), new City('Florencia'), new Phone('2460-1111')));

        $this->assertDatabaseCount('barbershops', 1);
        $this->assertSame('La Navaja', BarbershopModel::query()->findOrFail($id->value)->name);
    }

    /** A saved barbershop is read back as the same entity, and an unknown one is not found. */
    public function test_saved_barbershop_is_found(): void
    {
        $barbershop = $this->barbershop('Barbería Pura Navaja');

        $this->repository->save($barbershop);

        $this->assertEquals($barbershop, $this->repository->find($barbershop->id));
        $this->assertNull($this->repository->find(new BarbershopId('0192f0c4-0000-7000-8000-000000000999')));
    }

    /** Every new identity is a distinct time-ordered UUID. */
    public function test_next_id_returns_distinct_uuid_v7_values(): void
    {
        $first = $this->repository->nextId()->value;
        $second = $this->repository->nextId()->value;

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first);
        $this->assertNotSame($first, $second);
    }

    /** Build a new barbershop with a fresh identity. */
    private function barbershop(string $name): Barbershop
    {
        return Barbershop::create($this->repository->nextId(), new BarbershopName($name), new City('San Carlos'), new Phone('2460-0000'));
    }
}
