<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Barbershops;

use Illuminate\Support\Facades\DB;
use Src\Catalog\Barbershops\Domain\Entities\Barbershop;
use Src\Catalog\Barbershops\Domain\Exceptions\PublicLinkTakenException;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Catalog\Barbershops\Domain\ValueObjects\PublicLink;
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

    /** A saved barbershop keeps its name, city, phone and link. */
    public function test_barbershop_is_saved(): void
    {
        $barbershop = $this->barbershop('Barbería Pura Navaja', 'pura-navaja');

        $this->repository->save($barbershop);

        $model = BarbershopModel::query()->findOrFail($barbershop->id->value);
        $this->assertSame('Barbería Pura Navaja', $model->name);
        $this->assertSame('San Carlos', $model->city);
        $this->assertSame('2460-0000', $model->phone);
        $this->assertSame('pura-navaja', $model->public_link);
    }

    /** Saving a barbershop again updates its data instead of adding another row. */
    public function test_saving_the_same_barbershop_again_updates_it(): void
    {
        $id = $this->repository->nextId();
        $this->repository->save($this->barbershop('Pura Navaja', 'pura-navaja', $id));

        $this->repository->save(Barbershop::reconstitute($id, new BarbershopName('La Navaja'), new City('Florencia'), new Phone('2460-1111'), new PublicLink('pura-navaja')));

        $this->assertDatabaseCount('barbershops', 1);
        $model = BarbershopModel::query()->findOrFail($id->value);
        $this->assertSame('La Navaja', $model->name);
        $this->assertSame('Florencia', $model->city);
    }

    /** Renaming a barbershop never changes its link, even if the entity carries a different one (RF-02). */
    public function test_renaming_keeps_the_stored_link(): void
    {
        $id = $this->repository->nextId();
        $this->repository->save($this->barbershop('Barbería Pura Navaja', 'pura-navaja', $id));

        $this->repository->save(Barbershop::reconstitute($id, new BarbershopName('La Navaja Dorada'), new City('San Carlos'), new Phone('2460-0000'), new PublicLink('la-navaja-dorada')));

        $this->assertSame('pura-navaja', BarbershopModel::query()->findOrFail($id->value)->public_link);
        $this->assertSame('La Navaja Dorada', $this->repository->find($id)?->name->value);
        $this->assertSame('pura-navaja', $this->repository->find($id)->link->value);
    }

    /** A new barbershop with a link another one has is refused with the domain error, and the transaction stays usable (MT-07). */
    public function test_taken_link_is_refused_and_the_transaction_survives(): void
    {
        $this->repository->save($this->barbershop('Pura Navaja', 'pura-navaja'));

        DB::transaction(function (): void {
            try {
                $this->repository->save($this->barbershop('Barbería Pura Navaja', 'pura-navaja'));
                $this->fail('A repeated link was accepted.');
            } catch (PublicLinkTakenException) {
                // The refusal rolled back its own savepoint, so the outer transaction can go on.
            }

            $this->repository->save($this->barbershop('Barbería Pura Navaja', 'pura-navaja-2'));
        });

        $this->assertSame(['pura-navaja', 'pura-navaja-2'], BarbershopModel::query()->orderBy('public_link')->pluck('public_link')->all());
    }

    /** A saved barbershop is read back as the same entity, and an unknown one is not found. */
    public function test_saved_barbershop_is_found(): void
    {
        $barbershop = $this->barbershop('Barbería Pura Navaja', 'pura-navaja');

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

    /** Build a new barbershop with a fresh identity unless one is given. */
    private function barbershop(string $name, string $link, ?BarbershopId $id = null): Barbershop
    {
        return Barbershop::create($id ?? $this->repository->nextId(), new BarbershopName($name), new City('San Carlos'), new Phone('2460-0000'), new PublicLink($link));
    }
}
