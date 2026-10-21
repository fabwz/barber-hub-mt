<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Collaborators;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Catalog\Collaborators\Domain\Entities\Collaborator;
use Src\Catalog\Collaborators\Domain\Exceptions\BreaksOverlapException;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorAlreadyExistsException;
use Src\Catalog\Collaborators\Domain\Exceptions\ShiftsOverlapException;
use Src\Catalog\Collaborators\Domain\ValueObjects\BreakPeriod;
use Src\Catalog\Collaborators\Domain\ValueObjects\OfferedServiceId;
use Src\Catalog\Collaborators\Domain\ValueObjects\Shift;
use Src\Catalog\Collaborators\Domain\ValueObjects\WorkSchedule;
use Src\Catalog\Collaborators\Infrastructure\Persistence\Repositories\EloquentCollaboratorRepository;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\DayOfWeek;
use Src\Shared\Domain\ValueObjects\TimeOfDay;
use Src\Shared\Domain\ValueObjects\TimeRange;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

final class EloquentCollaboratorRepositoryTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private string $puraNavaja;

    private string $fadeLab;

    private EloquentCollaboratorRepository $repository;

    private string $haircut;

    private string $kidsHaircut;

    /** Two barbershops and a repository that works on the first one. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->insertBarbershop('pura-navaja');
        $this->fadeLab = $this->insertBarbershop('fade-lab');
        $this->repository = new EloquentCollaboratorRepository(new FixedBarbershopContext(new BarbershopId($this->puraNavaja)));
        $this->scopeQueriesTo($this->puraNavaja);
        $this->haircut = $this->insertService($this->puraNavaja, 'Corte clásico');
        $this->kidsHaircut = $this->insertService($this->puraNavaja, 'Corte infantil');
    }

    /** The lock reads the row with FOR UPDATE inside the transaction and finds only collaborators of the barbershop. */
    public function test_lock_takes_the_row_for_update_and_only_of_the_barbershop(): void
    {
        $kevin = $this->collaborator('kevin@barberhub.test');
        $this->repository->save($kevin);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->assertTrue($this->repository->lock($kevin->id));
        $this->assertFalse($this->repository->lock($this->repository->nextId()));

        $this->assertNotEmpty(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "collaborators"') && str_contains($sql, 'for update')));

        $this->scopeQueriesTo($this->fadeLab);
        $other = new EloquentCollaboratorRepository(new FixedBarbershopContext(new BarbershopId($this->fadeLab)));
        $this->assertFalse($other->lock($kevin->id));
    }

    /** A collaborator with services, shifts and breaks is read back unchanged. */
    public function test_a_saved_collaborator_is_read_back_unchanged(): void
    {
        $kevin = $this->collaborator('kevin@barberhub.test');
        $kevin->offer([new OfferedServiceId($this->kidsHaircut), new OfferedServiceId($this->haircut)]);
        $kevin->work(WorkSchedule::of(
            [$this->shift(2, '09:00', '12:00'), $this->shift(1, '13:00', '17:30'), $this->shift(1, '09:00', '12:00')],
            [new BreakPeriod(new DayOfWeek(1), $this->range('15:00', '15:30'))],
        ));

        $this->repository->save($kevin);

        $found = $this->repository->find($kevin->id);
        $this->assertSame($kevin->id->value, $found?->id->value);
        $this->assertTrue($found->active);
        $this->assertEqualsCanonicalizing([$this->haircut, $this->kidsHaircut], array_map(fn (OfferedServiceId $service): string => $service->value, $found->services));
        $this->assertSame(['1 09:00-12:00', '1 13:00-17:30', '2 09:00-12:00'], array_map(fn (Shift $shift): string => "{$shift->day->value} {$shift->range->start->value}-{$shift->range->end->value}", $found->schedule->shifts));
        $this->assertSame(['1 15:00-15:30'], array_map(fn (BreakPeriod $break): string => "{$break->day->value} {$break->range->start->value}-{$break->range->end->value}", $found->schedule->breaks));
        $this->assertEquals($found, $this->repository->findByUser($kevin->userId));
    }

    /** Saving again replaces the services, shifts and breaks instead of adding to them. */
    public function test_saving_again_replaces_the_children(): void
    {
        $kevin = $this->collaborator('kevin@barberhub.test');
        $kevin->offer([new OfferedServiceId($this->haircut)]);
        $kevin->work(WorkSchedule::of([$this->shift(1, '09:00', '12:00')], [new BreakPeriod(new DayOfWeek(1), $this->range('10:00', '10:30'))]));
        $this->repository->save($kevin);

        $kevin->offer([new OfferedServiceId($this->kidsHaircut)]);
        $kevin->work(WorkSchedule::of([$this->shift(1, '09:00', '12:00')], []));
        $kevin->deactivate();
        $this->repository->save($kevin);

        $found = $this->repository->find($kevin->id);
        $this->assertFalse($found?->active);
        $this->assertSame([$this->kidsHaircut], array_map(fn (OfferedServiceId $service): string => $service->value, $found->services));
        $this->assertCount(1, $found->schedule->shifts);
        $this->assertSame([], $found->schedule->breaks);
        $this->assertSame(1, DB::table('collaborator_shifts')->count());
    }

    /** A collaborator who offers nothing and works no day is stored and read back empty. */
    public function test_a_new_collaborator_is_read_back_empty(): void
    {
        $kevin = $this->collaborator('kevin@barberhub.test');
        $this->repository->save($kevin);

        $found = $this->repository->find($kevin->id);

        $this->assertSame([], $found?->services);
        $this->assertSame([], $found->schedule->shifts);
    }

    /** The collaborators come from the oldest to the newest, and only those of the barbershop of the context. */
    public function test_all_lists_the_collaborators_of_the_barbershop_from_the_oldest(): void
    {
        $first = $this->collaborator('kevin@barberhub.test');
        $second = $this->collaborator('ana@barberhub.test');
        $this->repository->save($first);
        DB::table('collaborators')->where('id', $first->id->value)->update(['created_at' => '2026-01-01 08:00:00-06']);
        $this->repository->save($second);
        $this->saveInFadeLab($this->collaborator('otro@barberhub.test'));
        $this->scopeQueriesTo($this->puraNavaja);

        $this->assertSame([$first->id->value, $second->id->value], array_map(fn (Collaborator $collaborator): string => $collaborator->id->value, $this->repository->all()));
    }

    /** Only active collaborators who have the service enabled are offered for it, oldest first (CO-01, DI-04). */
    public function test_active_offering_returns_only_active_collaborators_with_the_service(): void
    {
        $kevin = $this->collaborator('kevin@barberhub.test');
        $kevin->offer([new OfferedServiceId($this->haircut)]);
        $ana = $this->collaborator('ana@barberhub.test');
        $ana->offer([new OfferedServiceId($this->haircut), new OfferedServiceId($this->kidsHaircut)]);
        $luis = $this->collaborator('luis@barberhub.test');
        $luis->offer([new OfferedServiceId($this->kidsHaircut)]);
        $luis->deactivate();
        foreach ([$ana, $kevin, $luis] as $index => $collaborator) {
            $this->repository->save($collaborator);
            DB::table('collaborators')->where('id', $collaborator->id->value)->update(['created_at' => '2026-01-0'.($index + 1).' 08:00:00-06']);
        }

        $ids = fn (string $service): array => array_map(fn (Collaborator $collaborator): string => $collaborator->id->value, $this->repository->activeOffering(new OfferedServiceId($service)));

        $this->assertSame([$ana->id->value], $ids($this->kidsHaircut));
        $this->assertSame([$ana->id->value, $kevin->id->value], $ids($this->haircut));
    }

    /** A collaborator of another barbershop is neither found nor listed, whatever the identifier. */
    public function test_a_collaborator_of_another_barbershop_is_invisible(): void
    {
        $other = $this->collaborator('otro@barberhub.test');
        $this->saveInFadeLab($other);
        $this->scopeQueriesTo($this->puraNavaja);

        $this->assertNull($this->repository->find($other->id));
        $this->assertNull($this->repository->findByUser($other->userId));
        $this->assertSame([], $this->repository->all());
    }

    /** A second profile for the same account in the barbershop is translated to the domain exception. */
    public function test_a_second_profile_for_the_account_is_refused(): void
    {
        $kevin = $this->collaborator('kevin@barberhub.test');
        $this->repository->save($kevin);

        $this->expectException(CollaboratorAlreadyExistsException::class);

        $this->repository->save(Collaborator::create($this->repository->nextId(), $kevin->userId));
    }

    /** An overlap the use case did not catch is rejected by the database and translated, and the stored schedule stays. */
    public function test_an_overlap_that_reaches_the_database_is_translated_and_rolled_back(): void
    {
        $kevin = $this->collaborator('kevin@barberhub.test');
        $kevin->work(WorkSchedule::of([$this->shift(1, '09:00', '10:00')], []));
        $this->repository->save($kevin);

        $kevin->work(WorkSchedule::reconstitute([$this->shift(1, '09:00', '12:00'), $this->shift(1, '11:00', '14:00')], []));
        try {
            $this->repository->save($kevin);
            $this->fail('Overlapping shifts were stored.');
        } catch (ShiftsOverlapException) {
            $this->assertSame(['09:00-10:00'], array_map(fn (Shift $shift): string => "{$shift->range->start->value}-{$shift->range->end->value}", $this->repository->find($kevin->id)->schedule->shifts ?? []));
        }

        $kevin->work(WorkSchedule::reconstitute([], [new BreakPeriod(new DayOfWeek(1), $this->range('10:00', '11:00')), new BreakPeriod(new DayOfWeek(1), $this->range('10:30', '11:30'))]));

        $this->expectException(BreaksOverlapException::class);

        $this->repository->save($kevin);
    }

    /** Build a new collaborator whose account exists. */
    private function collaborator(string $email): Collaborator
    {
        $userId = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $userId, 'name' => $email, 'email' => $email, 'password' => 'hash']);

        return Collaborator::create($this->repository->nextId(), new UserId($userId));
    }

    /** Save a collaborator in Fade Lab, with a repository that works on it. */
    private function saveInFadeLab(Collaborator $collaborator): void
    {
        $this->scopeQueriesTo($this->fadeLab);
        new EloquentCollaboratorRepository(new FixedBarbershopContext(new BarbershopId($this->fadeLab)))->save($collaborator);
    }

    /** Build a shift. */
    private function shift(int $day, string $starts, string $ends): Shift
    {
        return new Shift(new DayOfWeek($day), $this->range($starts, $ends));
    }

    /** Build a range of times. */
    private function range(string $starts, string $ends): TimeRange
    {
        return TimeRange::between(new TimeOfDay($starts), new TimeOfDay($ends));
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function insertBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Insert a service in the barbershop the queries reach, and return its identifier. */
    private function insertService(string $barbershopId, string $name): string
    {
        $id = (string) Str::uuid7();
        DB::table('services')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'name' => $name, 'duration_minutes' => 30, 'price' => 7000]);

        return $id;
    }
}
