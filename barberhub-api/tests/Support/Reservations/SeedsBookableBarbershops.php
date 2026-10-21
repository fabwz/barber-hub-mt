<?php

declare(strict_types=1);

namespace Tests\Support\Reservations;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\Shared\ScopesQueriesToBarbershop;

/**
 * Two barbershops to book in, as the availability tests build them: Pura Navaja opens from Monday to Saturday from 9:00
 * to 19:00; Daniela works there on Tuesdays from 9:00 to 19:00 with a break from 12:00 to 13:00 and Kevin from 9:00 to
 * 12:00, both offering "Degradado" (45 minutes, ₡7.000). Fade Lab opens on Tuesdays and Mauricio offers "Corte" there.
 * Today is Friday 1 March 2030, 8:00.
 */
trait SeedsBookableBarbershops
{
    use ScopesQueriesToBarbershop;

    private const string TUESDAY_TEN = '2030-03-05T10:00:00-06:00';

    private string $puraNavaja;

    private string $fadeLab;

    private string $degradado;

    private string $corte;

    private string $daniela;

    private string $kevin;

    private string $mauricio;

    private string $owner;

    /** Fix the clock and build both barbershops with their hours, services and staff. */
    protected function seedBookableBarbershops(): void
    {
        $this->app->instance(Clock::class, new class implements Clock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica'));
            }
        });

        $this->puraNavaja = $this->insertBarbershop('pura-navaja');
        $this->fadeLab = $this->insertBarbershop('fade-lab');

        foreach (range(1, 6) as $day) {
            $this->insertHours($this->puraNavaja, $day);
        }
        $this->scopeQueriesTo($this->puraNavaja);
        $this->degradado = $this->insertService($this->puraNavaja, 'Degradado', 45, 7000);
        $this->daniela = $this->insertCollaborator($this->puraNavaja, 'Daniela', [$this->degradado], [[2, '09:00', '19:00']], [[2, '12:00', '13:00']]);
        $this->kevin = $this->insertCollaborator($this->puraNavaja, 'Kevin', [$this->degradado], [[2, '09:00', '12:00']]);
        $this->owner = $this->insertUser('Mauricio Dueño');
        $this->grantRole($this->owner, $this->puraNavaja, 'owner');

        $this->insertHours($this->fadeLab, 2);
        $this->scopeQueriesTo($this->fadeLab);
        $this->corte = $this->insertService($this->fadeLab, 'Corte', 30, 5000);
        $this->mauricio = $this->insertCollaborator($this->fadeLab, 'Mauricio', [$this->corte], [[2, '09:00', '19:00']]);
    }

    /** Authenticate as an account. */
    private function actAs(string $userId): void
    {
        Sanctum::actingAs(UserModel::query()->findOrFail($userId), []);
    }

    /** Address of the reservations of a barbershop. */
    private function reservationsUrl(string $barbershopId): string
    {
        return "/api/v1/barberias/{$barbershopId}/reservas";
    }

    /**
     * The body of a booking.
     *
     * @return array{servicio: string, barbero: string, inicio: string}
     */
    private function booking(?string $serviceId = null, ?string $collaboratorId = null, string $startsAt = self::TUESDAY_TEN): array
    {
        return ['servicio' => $serviceId ?? $this->degradado, 'barbero' => $collaboratorId ?? $this->daniela, 'inicio' => $startsAt];
    }

    /**
     * A problem document without the identifier of the request, which is different in each answer.
     *
     * @param  array<string, mixed>  $problem
     * @return array<string, mixed>
     */
    private function withoutInstance(array $problem): array
    {
        unset($problem['instance']);

        return $problem;
    }

    /** Count the rows of a table of a barbershop as that barbershop sees them. */
    private function countIn(string $table, string $barbershopId): int
    {
        $this->scopeQueriesTo($barbershopId);

        return DB::table($table)->count();
    }

    /** Insert an account without any role and return its identifier. */
    private function insertUser(string $name): string
    {
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'name' => $name, 'email' => "{$id}@barberhub.test", 'password' => 'hash']);

        return $id;
    }

    /** Give an account a role in a barbershop. */
    private function grantRole(string $userId, string $barbershopId, string $role): void
    {
        DB::table('memberships')->insert(['id' => (string) Str::uuid7(), 'user_id' => $userId, 'barbershop_id' => $barbershopId, 'role' => $role]);
    }

    private function insertBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    private function insertHours(string $barbershopId, int $day): void
    {
        $this->scopeQueriesTo($barbershopId);
        DB::table('opening_hours')->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $barbershopId, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '19:00']);
    }

    private function insertService(string $barbershopId, string $name, int $minutes, int $price, bool $active = true): string
    {
        $id = (string) Str::uuid7();
        DB::table('services')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'name' => $name, 'duration_minutes' => $minutes, 'price' => $price, 'is_active' => $active]);

        return $id;
    }

    /**
     * Insert a collaborator, with the role of the account, and its services, shifts and breaks given as [day, from, to].
     *
     * @param  list<string>  $services
     * @param  list<array{int, string, string}>  $shifts
     * @param  list<array{int, string, string}>  $breaks
     */
    private function insertCollaborator(string $barbershopId, string $name, array $services, array $shifts, array $breaks = []): string
    {
        $userId = $this->insertUser($name);
        $this->grantRole($userId, $barbershopId, 'collaborator');
        $id = (string) Str::uuid7();
        DB::table('collaborators')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'user_id' => $userId]);

        foreach ($services as $service) {
            DB::table('collaborator_services')->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $barbershopId, 'collaborator_id' => $id, 'service_id' => $service]);
        }
        foreach ([['collaborator_shifts', $shifts], ['collaborator_breaks', $breaks]] as [$table, $rows]) {
            foreach ($rows as [$day, $from, $to]) {
                DB::table($table)->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $barbershopId, 'collaborator_id' => $id, 'day_of_week' => $day, 'starts_at' => $from, 'ends_at' => $to]);
            }
        }

        return $id;
    }
}
