<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Models\BarbershopModel;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;

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

    /** Seed the three demo barbershops with their owners, for local development only. */
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The demo barbershops are never seeded in production: their accounts share a known password.');
        }

        foreach (self::BARBERSHOPS as $barbershop) {
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
        }
    }
}
