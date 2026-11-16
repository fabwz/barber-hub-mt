<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Barbershops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

/** The public directory lists only the barbershops whose owner chose to appear, with their public fields, and only the owner chooses (RF-54, MT-07, MT-09, MT-10, SE-04). */
final class BarbershopDirectoryTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private const string DIRECTORY = '/api/v1/directorio';

    private string $puraNavaja;

    private string $fadeLab;

    private UserModel $owner;

    private UserModel $collaborator;

    private UserModel $customer;

    private UserModel $stranger;

    /** Pura Navaja has an owner, a collaborator and a customer; Fade Lab has its own owner. None appears in the directory yet. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->createBarbershop('Barbería Pura Navaja', 'Ciudad Quesada', 'pura-navaja');
        $this->fadeLab = $this->createBarbershop('Fade Lab', 'Florencia', 'fade-lab');
        $this->owner = $this->createMember('ana@barberhub.test', $this->puraNavaja, 'owner');
        $this->collaborator = $this->createMember('beto@barberhub.test', $this->puraNavaja, 'collaborator');
        $this->customer = $this->createMember('cata@barberhub.test', $this->puraNavaja, 'customer');
        $this->createMember('luis@barberhub.test', $this->fadeLab, 'owner');
        $this->stranger = $this->createMember('dani@barberhub.test', null, null);
    }

    /** The directory answers without a session and, by default, no barbershop appears in it. */
    public function test_by_default_no_barbershop_appears(): void
    {
        $this->getJson(self::DIRECTORY)->assertOk()->assertExactJson([
            'data' => [],
            'meta' => ['pagina' => 1, 'por_pagina' => 15, 'total' => 0, 'ultima_pagina' => 1],
        ]);
    }

    /** Only the barbershops whose owner turned the switch on appear, by name, and each brings just name, city, logo and link. */
    public function test_only_the_listed_barbershops_appear_with_their_public_fields(): void
    {
        $this->list($this->puraNavaja);
        $this->createBarbershop('Barbería Don Chepe', 'La Fortuna', 'don-chepe');

        $this->getJson(self::DIRECTORY)->assertOk()->assertExactJson([
            'data' => [['nombre' => 'Barbería Pura Navaja', 'ciudad' => 'Ciudad Quesada', 'logo' => null, 'enlace' => 'pura-navaja']],
            'meta' => ['pagina' => 1, 'por_pagina' => 15, 'total' => 1, 'ultima_pagina' => 1],
        ]);
    }

    /** The answer has no identifier, phone, email or data of the owner, and nothing of the barbershops that do not appear. */
    public function test_the_answer_gives_away_no_contact_data_no_identifier_and_nothing_of_the_unlisted(): void
    {
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['contact_email' => 'contacto@purenavaja.test', 'address' => 'Frente al parque', 'whatsapp_url' => 'https://wa.me/50600000000']);
        $this->list($this->puraNavaja);

        $content = (string) $this->getJson(self::DIRECTORY)->assertOk()->getContent();

        foreach (['2460-0000', 'contacto@purenavaja.test', 'Frente al parque', 'wa.me', 'ana@barberhub.test', 'Fade Lab', 'fade-lab', 'Florencia', $this->puraNavaja, $this->fadeLab] as $secret) {
            $this->assertStringNotContainsString($secret, $content, "The directory gives away {$secret}");
        }
        $this->assertEqualsCanonicalizing(['ciudad', 'enlace', 'logo', 'nombre'], array_keys($this->getJson(self::DIRECTORY)->json('data.0')));
    }

    /** The search finds by name or by city, ignoring case and accents, and never reaches the barbershops that do not appear. */
    public function test_search_by_name_or_city_ignores_case_and_accents(): void
    {
        $this->list($this->puraNavaja);
        $this->list($this->fadeLab);
        $this->createBarbershop('Barbería Oculta', 'Ciudad Quesada', 'oculta');

        $this->assertSame(['pura-navaja'], $this->links('?buscar=PURA'));
        $this->assertSame(['pura-navaja'], $this->links('?buscar=barberia'));
        $this->assertSame(['pura-navaja'], $this->links('?buscar='.rawurlencode('Barbería')));
        $this->assertSame(['fade-lab'], $this->links('?buscar=florencia'));
        $this->assertSame(['pura-navaja'], $this->links('?buscar=quesada'), 'The unlisted barbershop of the same city does not appear.');
        $this->assertSame(['pura-navaja', 'fade-lab'], $this->links('?buscar=a'));
        $this->assertSame([], $this->links('?buscar=nadie'));
        $this->assertSame(['pura-navaja', 'fade-lab'], $this->links('?buscar='), 'A blank search is no search.');
    }

    /** The wildcards of the search match only themselves. */
    public function test_the_wildcards_of_the_search_match_only_themselves(): void
    {
        $this->list($this->puraNavaja);

        $this->assertSame([], $this->links('?buscar=%25'));
        $this->assertSame([], $this->links('?buscar=_'));
        $this->assertSame([], $this->links('?buscar=%5C'));
    }

    /** The listing is ordered by name and paginated with the project's `pagina` and `por_pagina`. */
    public function test_it_is_ordered_by_name_and_paginated(): void
    {
        foreach (['Delta', 'Alfa', 'Charlie', 'Bravo'] as $name) {
            $this->list($this->createBarbershop($name, 'San Carlos', Str::slug($name)));
        }

        $this->getJson(self::DIRECTORY.'?por_pagina=3')->assertOk()
            ->assertJsonPath('meta', ['pagina' => 1, 'por_pagina' => 3, 'total' => 4, 'ultima_pagina' => 2])
            ->assertJsonPath('data.0.nombre', 'Alfa')
            ->assertJsonPath('data.2.nombre', 'Charlie');
        $second = $this->getJson(self::DIRECTORY.'?por_pagina=3&pagina=2')->assertOk();
        $this->assertSame(['Delta'], array_column($second->json('data'), 'nombre'));
        $this->getJson(self::DIRECTORY.'?pagina=9')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 4);
        $this->getJson(self::DIRECTORY.'?por_pagina=51')->assertUnprocessable();
        $this->getJson(self::DIRECTORY.'?pagina=0')->assertUnprocessable();
    }

    /** A barbershop that does not appear is still reached by its link (MT-07, RD-18). */
    public function test_an_unlisted_barbershop_is_still_reachable_by_its_link(): void
    {
        $this->getJson(self::DIRECTORY)->assertOk()->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/enlaces/pura-navaja')->assertOk()->assertJsonPath('data.id', $this->puraNavaja);
    }

    /** The owner turns the switch on and off, and each change leaves its own entry in the technical record, with no sensitive data (SE-04). */
    public function test_the_owner_switches_the_directory_and_the_change_is_recorded(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), ['aparece_en_directorio' => true])->assertOk()
            ->assertJsonPath('data.aparece_en_directorio', true)->assertJsonPath('data.bonos_activos', false);
        $this->assertSame(['pura-navaja'], $this->links(''));

        $this->patchJson($this->url($this->puraNavaja), ['aparece_en_directorio' => true])->assertOk();
        $this->patchJson($this->url($this->puraNavaja), ['aparece_en_directorio' => false])->assertOk();
        $this->assertSame([], $this->links(''));

        $entries = $this->entries();
        $this->assertSame(['barbershop.directory_listing.updated', 'barbershop.directory_listing.updated'], array_column($entries, 'action'));
        $this->assertSame([$this->owner->id, $this->owner->id], array_column($entries, 'actor_user_id'));
        $this->assertEquals([
            ['listed_in_directory' => ['previous' => false, 'new' => true]],
            ['listed_in_directory' => ['previous' => true, 'new' => false]],
        ], array_map(fn (array $entry): mixed => json_decode((string) $entry['changes'], true), $entries));
        $this->assertStringNotContainsString('ana@barberhub.test', json_encode($entries, JSON_THROW_ON_ERROR));
    }

    /** A change of the directory next to one of the vouchers leaves one entry for each action, and the entry of the features does not carry the directory. */
    public function test_a_change_of_both_leaves_one_entry_per_action(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), ['aparece_en_directorio' => true, 'bonos_activos' => true])->assertOk();

        $entries = $this->entries();
        $this->assertSame(['barbershop.features.updated', 'barbershop.directory_listing.updated'], array_column($entries, 'action'));
        $this->assertEquals([
            ['vouchers_enabled' => ['previous' => false, 'new' => true]],
            ['listed_in_directory' => ['previous' => false, 'new' => true]],
        ], array_map(fn (array $entry): mixed => json_decode((string) $entry['changes'], true), $entries));
    }

    /** A change that does not touch the directory leaves it as it was and records nothing about it. */
    public function test_a_change_of_other_functions_leaves_the_directory_as_it_was(): void
    {
        $this->list($this->puraNavaja);
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), ['bonos_activos' => true])->assertOk()->assertJsonPath('data.aparece_en_directorio', true);

        $this->assertSame(['pura-navaja'], $this->links(''));
        $this->assertSame(['barbershop.features.updated'], array_column($this->entries(), 'action'));
    }

    /**
     * Bodies that are not booleans.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidChanges(): iterable
    {
        yield 'as text' => [['aparece_en_directorio' => 'quizá']];
        yield 'as a number' => [['aparece_en_directorio' => 5]];
        yield 'null' => [['aparece_en_directorio' => null]];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalidChanges')]
    public function test_a_value_that_is_not_a_boolean_is_refused_and_changes_nothing(array $body): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), $body)->assertUnprocessable();

        $this->assertSame([], $this->links(''));
        $this->assertCount(0, $this->entries());
    }

    /** A collaborator and a customer are forbidden, a person without a role and the owner of another barbershop find nothing, and nothing changes (UP-03, RD-18). */
    public function test_only_the_owner_changes_the_switch(): void
    {
        foreach ([$this->collaborator, $this->customer] as $person) {
            Sanctum::actingAs($person, []);
            $this->patchJson($this->url($this->puraNavaja), ['aparece_en_directorio' => true])->assertForbidden();
        }

        Sanctum::actingAs($this->stranger, []);
        $stranger = $this->patchJson($this->url($this->puraNavaja), ['aparece_en_directorio' => true]);
        Sanctum::actingAs(UserModel::query()->where('email', 'luis@barberhub.test')->firstOrFail(), []);
        $foreign = $this->patchJson($this->url($this->puraNavaja), ['aparece_en_directorio' => true]);
        $missing = $this->patchJson($this->url((string) Str::uuid7()), ['aparece_en_directorio' => true]);

        $stranger->assertNotFound();
        $foreign->assertNotFound();
        $this->assertSame($missing->json('type'), $foreign->json('type'));
        $this->assertSame($missing->json('detail'), $stranger->json('detail'));
        $this->assertSame([], $this->links(''));
        $this->assertCount(0, $this->entries());
    }

    /** The owner of one barbershop changes only its own entry in the directory, and the barbershop comes only from the route (RD-18). */
    public function test_the_change_reaches_only_the_barbershop_of_the_route(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), ['aparece_en_directorio' => true, 'barberia' => $this->fadeLab, 'barbershop_id' => $this->fadeLab])->assertOk();

        $this->assertSame(['pura-navaja'], $this->links(''));
    }

    private function url(string $barbershopId): string
    {
        return "/api/v1/barberias/{$barbershopId}/administracion/funciones";
    }

    /**
     * The links the directory lists for a query string, in order.
     *
     * @return list<string>
     */
    private function links(string $query): array
    {
        return array_column($this->getJson(self::DIRECTORY.$query)->assertOk()->json('data'), 'enlace');
    }

    /**
     * The entries of the technical record of Pura Navaja, in order, read as that barbershop.
     *
     * @return list<array<string, mixed>>
     */
    private function entries(): array
    {
        $this->scopeQueriesTo($this->puraNavaja);

        return array_values(DB::table('audit_log')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
    }

    /** Switch the barbershop on in the directory, as its owner would. */
    private function list(string $barbershopId): void
    {
        DB::table('barbershops')->where('id', $barbershopId)->update(['listed_in_directory' => true]);
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function createBarbershop(string $name, string $city, string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $name, 'city' => $city, 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Create a person, with a role in a barbershop unless none is given. */
    private function createMember(string $email, ?string $barbershopId, ?string $role): UserModel
    {
        $user = UserModel::query()->create(['name' => $email, 'email' => $email, 'password' => 'hash']);

        if ($barbershopId !== null) {
            MembershipModel::query()->create(['user_id' => $user->id, 'barbershop_id' => $barbershopId, 'role' => $role, 'is_active' => true]);
        }

        return $user;
    }
}
