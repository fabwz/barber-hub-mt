<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Barbershops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Catalog\Barbershops\Domain\Entities\Barbershop;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentPolicy;
use Src\Catalog\Barbershops\Domain\ValueObjects\PublicLink;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Repositories\EloquentBarbershopRepository;
use Src\Shared\Domain\ValueObjects\Phone;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class PublicBarbershopLinkTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string NOT_FOUND = '/problemas/no-encontrado';

    /** The link opens the barbershop without any token and exposes only its public data (RF-02). */
    public function test_link_opens_the_barbershop_without_a_token(): void
    {
        $id = $this->insertBarbershop('Barbería Pura Navaja', 'pura-navaja');
        $this->insertBarbershop('Barbería La Navaja', 'la-navaja');

        $this->getJson('/api/v1/enlaces/pura-navaja')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $id,
                    'nombre' => 'Barbería Pura Navaja',
                    'ciudad' => 'San Carlos',
                    'telefono' => '2460-0000',
                ],
            ]);
    }

    /** A numbered link opens its own barbershop and not the first one with that name. */
    public function test_numbered_link_opens_the_homonym(): void
    {
        $this->insertBarbershop('Barbería Pura Navaja', 'pura-navaja');
        $second = $this->insertBarbershop('Barbería Pura Navaja', 'pura-navaja-2');

        $this->getJson('/api/v1/enlaces/pura-navaja-2')->assertOk()->assertJsonPath('data.id', $second);
    }

    /** A link nobody has answers "Esta barbería no existe" as a not-found problem (RF-02). */
    public function test_unknown_link_is_not_found(): void
    {
        $this->insertBarbershop('Barbería Pura Navaja', 'pura-navaja');

        $this->getJson('/api/v1/enlaces/no-existe')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', rtrim((string) config('app.url'), '/').self::NOT_FOUND)
            ->assertJsonPath('status', 404)
            ->assertJsonPath('detail', 'Esta barbería no existe');
    }

    /** A link outside the format gets the same answer as a missing one, so nothing is queried to tell them apart. */
    #[DataProvider('malformedLinks')]
    public function test_malformed_link_is_not_found(string $link): void
    {
        $this->insertBarbershop('Barbería Pura Navaja', 'pura-navaja');

        $this->getJson('/api/v1/enlaces/'.$link)
            ->assertNotFound()
            ->assertJsonPath('type', rtrim((string) config('app.url'), '/').self::NOT_FOUND)
            ->assertJsonPath('detail', 'Esta barbería no existe');
    }

    /**
     * Links outside the format that still reach the route.
     *
     * @return array<string, array{string}>
     */
    public static function malformedLinks(): array
    {
        return [
            'uppercase' => ['Pura-Navaja'],
            'underscore' => ['pura_navaja'],
            'encoded space' => ['pura%20navaja'],
            'encoded accent' => ['barber%C3%ADa'],
            'leading hyphen' => ['-pura-navaja'],
            'double hyphen' => ['pura--navaja'],
            'uppercase identifier' => ['0192F0C4-7B1E-7C3A-9F1D-2B6A4E8C0D22'],
            'too long' => [str_repeat('a', 121)],
        ];
    }

    /** Renaming a barbershop does not change its link: the old link still opens it with the new name (RF-02). */
    public function test_renaming_does_not_change_the_link(): void
    {
        $repository = new EloquentBarbershopRepository;
        $id = $repository->nextId();
        $repository->save(Barbershop::create($id, new BarbershopName('Barbería Pura Navaja'), new City('San Carlos'), new Phone('2460-0000'), new PublicLink('pura-navaja')));

        $repository->save(Barbershop::reconstitute($id, new BarbershopName('La Navaja Dorada'), new City('San Carlos'), new Phone('2460-0000'), new PublicLink('la-navaja-dorada'), PaymentPolicy::initial()));

        $this->getJson('/api/v1/enlaces/pura-navaja')->assertOk()->assertJsonPath('data.nombre', 'La Navaja Dorada');
        $this->getJson('/api/v1/enlaces/la-navaja-dorada')->assertNotFound();
    }

    /** The route is documented as public: it asks for no token. */
    public function test_route_does_not_require_authentication(): void
    {
        $route = app('router')->getRoutes()->getByName('catalog.barbershops.show-by-link');

        $this->assertNotNull($route);
        $this->assertSame([], array_values(array_filter($route->gatherMiddleware(), fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'auth'))));
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function insertBarbershop(string $name, string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $name, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }
}
