<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Models\BarbershopModel;
use Src\Catalog\Collaborators\Infrastructure\Persistence\Models\CollaboratorModel;
use Src\Catalog\Services\Infrastructure\Persistence\Models\ServiceModel;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;

final class DemoBarbershopSeeder extends Seeder
{
    /** Shared by every demo account; it meets the password policy (SE-07). */
    public const string PASSWORD = 'Demo#Barber2026';

    /**
     * Fictitious barbershops (CL-08, RNF-23, RL-04). Fixed identifiers and links make a second run update the same rows.
     * The owner is a fictitious account with an active owner membership; the gateway is null for no gateway.
     *
     * @var list<array{id: string, name: string, city: string, phone: string, link: string, gateway: string|null, owner: array{id: string, membership: string, name: string, email: string, phone: string}}>
     */
    public const array BARBERSHOPS = [
        [
            'id' => '0192f0c4-0000-7000-8000-00000000b001',
            'name' => 'Barbería Pura Navaja',
            'city' => 'Ciudad Quesada',
            'phone' => '0000-0001',
            'link' => 'pura-navaja',
            'gateway' => 'onvo',
            'owner' => [
                'id' => '0192f0c4-0000-7000-8000-00000000a001',
                'membership' => '0192f0c4-0000-7000-8000-00000000c001',
                'name' => 'Mauricio Solano',
                'email' => 'mauricio@puranavaja.test',
                'phone' => '0000-0011',
            ],
        ],
        [
            'id' => '0192f0c4-0000-7000-8000-00000000b002',
            'name' => 'Fade Lab',
            'city' => 'Fortuna',
            'phone' => '0000-0002',
            'link' => 'fade-lab',
            'gateway' => 'paypal',
            'owner' => [
                'id' => '0192f0c4-0000-7000-8000-00000000a002',
                'membership' => '0192f0c4-0000-7000-8000-00000000c002',
                'name' => 'Sofía Araya',
                'email' => 'sofia@fadelab.test',
                'phone' => '0000-0012',
            ],
        ],
        [
            'id' => '0192f0c4-0000-7000-8000-00000000b003',
            'name' => 'Barbería Don Chepe',
            'city' => 'Aguas Zarcas',
            'phone' => '0000-0003',
            'link' => 'don-chepe',
            'gateway' => null,
            'owner' => [
                'id' => '0192f0c4-0000-7000-8000-00000000a003',
                'membership' => '0192f0c4-0000-7000-8000-00000000c003',
                'name' => 'José Alvarado',
                'email' => 'chepe@donchepe.test',
                'phone' => '0000-0013',
            ],
        ],
    ];

    /**
     * Fictitious catalog, hours and staff of each barbershop, by public link (CL-08). Prices are whole colones with the 13 % VAT
     * already included (SV-02). Days run from 1 (Monday) to 7 (Sunday); hours, shifts and breaks are [days, from, to], and a day
     * without hours is closed (HO-01). Every shift falls inside the hours of its barbershop (HO-02). A staff member that is "owner"
     * is the owner's own account with a collaborator profile and keeps the owner role; the others get a collaborator membership.
     * Services and staff take their fixed identifiers from their position, so a second run updates the same rows.
     *
     * @var array<string, array{
     *     hours: list<array{list<int>, string, string}>,
     *     services: list<array{string, int, int}>,
     *     staff: list<array{name: string, email: string, phone: string|null, owner: bool, services: list<string>, shifts: list<array{list<int>, string, string}>, breaks: list<array{list<int>, string, string}>}>
     * }>
     */
    public const array CATALOG = [
        'pura-navaja' => [
            'hours' => [[[1, 2, 3, 4, 5], '09:00', '19:00'], [[6], '08:00', '17:00']],
            'services' => [
                ['Corte clásico', 30, 7000],
                ['Degradado', 45, 9000],
                ['Corte infantil', 30, 5500],
                ['Barba', 30, 5000],
                ['Corte + barba', 60, 12500],
                ['Perfilado de cejas', 15, 2500],
                ['Tratamiento capilar', 45, 10000],
            ],
            'staff' => [
                [
                    'name' => 'Mauricio Solano', 'email' => 'mauricio@puranavaja.test', 'phone' => null, 'owner' => true,
                    'services' => ['Corte clásico', 'Degradado', 'Corte infantil', 'Barba', 'Corte + barba'],
                    'shifts' => [[[1, 2, 3, 4, 5], '09:00', '19:00'], [[6], '08:00', '14:00']],
                    'breaks' => [[[2], '12:00', '13:00']],
                ],
                [
                    'name' => 'Daniela Vargas', 'email' => 'daniela@puranavaja.test', 'phone' => '0000-0021', 'owner' => false,
                    'services' => ['Corte clásico', 'Degradado', 'Corte infantil', 'Barba', 'Corte + barba', 'Perfilado de cejas', 'Tratamiento capilar'],
                    'shifts' => [[[2, 3, 4, 5], '10:00', '19:00'], [[6], '08:00', '17:00']],
                    'breaks' => [[[2, 3, 4, 5], '13:00', '14:00'], [[6], '12:00', '12:30']],
                ],
                [
                    'name' => 'Kevin Mora', 'email' => 'kevin@puranavaja.test', 'phone' => '0000-0022', 'owner' => false,
                    'services' => ['Corte clásico', 'Degradado', 'Barba', 'Corte + barba', 'Perfilado de cejas', 'Tratamiento capilar'],
                    'shifts' => [[[1, 2, 3, 4, 5], '09:00', '17:00'], [[6], '09:00', '15:00']],
                    'breaks' => [[[1, 2, 3, 4, 5], '12:30', '13:15'], [[6], '11:00', '11:30']],
                ],
                [
                    'name' => 'Esteban Quirós', 'email' => 'esteban@puranavaja.test', 'phone' => '0000-0023', 'owner' => false,
                    'services' => ['Corte clásico', 'Corte infantil', 'Barba', 'Perfilado de cejas', 'Tratamiento capilar'],
                    'shifts' => [[[1, 3, 5], '11:00', '19:00'], [[6], '08:00', '17:00']],
                    'breaks' => [[[1, 3, 5], '15:00', '15:30'], [[6], '12:00', '13:00']],
                ],
            ],
        ],
        'fade-lab' => [
            'hours' => [[[2, 3, 4, 5, 6], '10:00', '20:00'], [[7], '09:00', '14:00']],
            'services' => [
                ['Degradado', 45, 11000],
                ['Corte de tijera', 40, 9500],
                ['Diseño de líneas', 20, 3000],
                ['Barba con toalla caliente', 30, 7000],
                ['Coloración', 90, 25000],
                ['Corte + degradado de barba', 60, 15000],
            ],
            'staff' => [
                [
                    'name' => 'Valeria Campos', 'email' => 'valeria@fadelab.test', 'phone' => '0000-0031', 'owner' => false,
                    'services' => ['Degradado', 'Corte de tijera', 'Diseño de líneas', 'Coloración'],
                    'shifts' => [[[2, 3, 4, 5, 6], '10:00', '20:00'], [[7], '09:00', '14:00']],
                    'breaks' => [[[2, 3, 4, 5, 6], '14:00', '15:00']],
                ],
                [
                    'name' => 'Andrés Rojas', 'email' => 'andres@fadelab.test', 'phone' => '0000-0032', 'owner' => false,
                    'services' => ['Degradado', 'Diseño de líneas', 'Barba con toalla caliente', 'Corte + degradado de barba'],
                    'shifts' => [[[3, 4, 5, 6], '12:00', '20:00'], [[7], '09:00', '13:00']],
                    'breaks' => [[[3, 4, 5, 6], '16:00', '16:30']],
                ],
            ],
        ],
        'don-chepe' => [
            'hours' => [[[1, 2, 3, 4, 5], '07:00', '17:00'], [[6], '07:00', '12:00']],
            'services' => [
                ['Corte tradicional', 30, 4500],
                ['Corte para niño', 30, 3500],
                ['Afeitado clásico', 30, 4000],
                ['Corte + afeitado', 60, 7500],
                ['Cejas y orejas', 10, 1500],
            ],
            'staff' => [
                [
                    'name' => 'Ronny Vargas', 'email' => 'ronny@donchepe.test', 'phone' => '0000-0041', 'owner' => false,
                    'services' => ['Corte tradicional', 'Corte para niño', 'Afeitado clásico', 'Corte + afeitado', 'Cejas y orejas'],
                    'shifts' => [[[1, 2, 3, 4, 5], '07:00', '17:00'], [[6], '07:00', '12:00']],
                    'breaks' => [[[1, 2, 3, 4, 5], '11:30', '12:30']],
                ],
                [
                    'name' => 'Luis Chaves', 'email' => 'luis@donchepe.test', 'phone' => '0000-0042', 'owner' => false,
                    'services' => ['Corte tradicional', 'Afeitado clásico', 'Cejas y orejas'],
                    'shifts' => [[[1, 2, 3, 4, 5], '08:00', '16:00']],
                    'breaks' => [[[1, 2, 3, 4, 5], '12:00', '12:45']],
                ],
            ],
        ],
    ];

    /** Seed the three demo barbershops with their owners, catalog, hours and staff, for local development only. */
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The demo barbershops are never seeded in production: their accounts share a known password.');
        }

        foreach (self::BARBERSHOPS as $position => $barbershop) {
            BarbershopModel::query()->updateOrCreate(
                ['id' => $barbershop['id']],
                [
                    'name' => $barbershop['name'],
                    'city' => $barbershop['city'],
                    'phone' => $barbershop['phone'],
                    'public_link' => $barbershop['link'],
                    'payment_gateway' => $barbershop['gateway'],
                ],
            );

            $owner = $barbershop['owner'];

            UserModel::query()->updateOrCreate(
                ['id' => $owner['id']],
                [
                    'name' => $owner['name'],
                    'email' => $owner['email'],
                    'password' => Hash::make(self::PASSWORD),
                    'phone' => $owner['phone'],
                    'is_active' => true,
                    'terms_accepted_at' => now(),
                    'privacy_accepted_at' => now(),
                ],
            );

            MembershipModel::query()->updateOrCreate(
                ['id' => $owner['membership']],
                [
                    'user_id' => $owner['id'],
                    'barbershop_id' => $barbershop['id'],
                    'role' => Role::Owner->value,
                    'is_active' => true,
                ],
            );

            $this->seedCatalog($position + 1, $barbershop);
        }
    }

    /**
     * Seed the services, weekly hours and staff of one barbershop. Its tables are protected by row-level security, so the
     * whole block runs scoped to the barbershop. Entities are updated by fixed identifier and the child sets (hours, shifts,
     * breaks and enabled services) are replaced, so a second run leaves the same rows.
     *
     * @param  array{id: string, link: string, owner: array{id: string}}  $barbershop
     */
    private function seedCatalog(int $number, array $barbershop): void
    {
        $catalog = self::CATALOG[$barbershop['link']];
        $barbershopId = $barbershop['id'];

        app(BarbershopTransaction::class)->run($barbershopId, function () use ($number, $barbershop, $barbershopId, $catalog): void {
            $serviceIds = [];
            foreach ($catalog['services'] as $index => [$name, $minutes, $price]) {
                $serviceIds[$name] = self::identifier('d', $number, $index + 1);

                ServiceModel::query()->updateOrCreate(
                    ['id' => $serviceIds[$name]],
                    ['barbershop_id' => $barbershopId, 'name' => $name, 'duration_minutes' => $minutes, 'price' => $price, 'is_active' => true],
                );
            }

            DB::table('opening_hours')->where('barbershop_id', $barbershopId)->delete();
            foreach ($catalog['hours'] as [$days, $from, $to]) {
                foreach ($days as $day) {
                    DB::table('opening_hours')->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $barbershopId, 'day_of_week' => $day, 'opens_at' => $from, 'closes_at' => $to]);
                }
            }

            foreach ($catalog['staff'] as $index => $member) {
                $this->seedCollaborator($number, $index + 1, $barbershop, $member, $serviceIds);
            }
        });
    }

    /**
     * Seed one collaborator: the account and membership (the owner keeps hers), the profile and its replaced child sets.
     * Demo accounts do not ask for a password change, so they can be used right away.
     *
     * @param  array{id: string, owner: array{id: string}}  $barbershop
     * @param  array{name: string, email: string, phone: string|null, owner: bool, services: list<string>, shifts: list<array{list<int>, string, string}>, breaks: list<array{list<int>, string, string}>}  $member
     * @param  array<string, string>  $serviceIds
     */
    private function seedCollaborator(int $number, int $position, array $barbershop, array $member, array $serviceIds): void
    {
        $barbershopId = $barbershop['id'];

        if ($member['owner']) {
            $userId = $barbershop['owner']['id'];
        } else {
            $userId = self::identifier('f', $number, $position);

            UserModel::query()->updateOrCreate(
                ['id' => $userId],
                [
                    'name' => $member['name'],
                    'email' => $member['email'],
                    'password' => Hash::make(self::PASSWORD),
                    'phone' => $member['phone'],
                    'is_active' => true,
                    'must_change_password' => false,
                    'terms_accepted_at' => now(),
                    'privacy_accepted_at' => now(),
                ],
            );

            MembershipModel::query()->updateOrCreate(
                ['id' => self::identifier('9', $number, $position)],
                ['user_id' => $userId, 'barbershop_id' => $barbershopId, 'role' => Role::Collaborator->value, 'is_active' => true],
            );
        }

        $collaboratorId = self::identifier('e', $number, $position);

        CollaboratorModel::query()->updateOrCreate(
            ['id' => $collaboratorId],
            ['barbershop_id' => $barbershopId, 'user_id' => $userId, 'is_active' => true],
        );

        foreach (['collaborator_services', 'collaborator_shifts', 'collaborator_breaks'] as $table) {
            DB::table($table)->where('collaborator_id', $collaboratorId)->delete();
        }

        foreach ($member['services'] as $name) {
            DB::table('collaborator_services')->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $barbershopId, 'collaborator_id' => $collaboratorId, 'service_id' => $serviceIds[$name]]);
        }

        foreach (['collaborator_shifts' => $member['shifts'], 'collaborator_breaks' => $member['breaks']] as $table => $entries) {
            foreach ($entries as [$days, $from, $to]) {
                foreach ($days as $day) {
                    DB::table($table)->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $barbershopId, 'collaborator_id' => $collaboratorId, 'day_of_week' => $day, 'starts_at' => $from, 'ends_at' => $to]);
                }
            }
        }
    }

    /** Build the fixed identifier of a demo entity from its kind, the barbershop number and its position. */
    private static function identifier(string $kind, int $barbershop, int $position): string
    {
        return sprintf('0192f0c4-0000-7000-8000-00000000%s%d%02d', $kind, $barbershop, $position);
    }
}
