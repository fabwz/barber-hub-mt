<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Barbershops;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** The owner reads and edits the name, city, phone, address, contact email and social links, and the public link shows them (RF-01, RF-02, MT-07, UP-03). */
final class BarbershopDetailsTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private string $puraNavaja;

    private string $fadeLab;

    private UserModel $owner;

    private UserModel $collaborator;

    private UserModel $customer;

    private UserModel $stranger;

    private UserModel $otherOwner;

    /** Two barbershops; Pura Navaja has an owner, a collaborator and a customer, and Fade Lab has its own owner. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->createBarbershop('Barbería Pura Navaja', 'pura-navaja');
        $this->fadeLab = $this->createBarbershop('Fade Lab', 'fade-lab');
        $this->owner = $this->createMember('ana@barberhub.test', $this->puraNavaja, 'owner');
        $this->collaborator = $this->createMember('beto@barberhub.test', $this->puraNavaja, 'collaborator');
        $this->customer = $this->createMember('cata@barberhub.test', $this->puraNavaja, 'customer');
        $this->otherOwner = $this->createMember('luis@barberhub.test', $this->fadeLab, 'owner');
        $this->stranger = $this->createMember('dani@barberhub.test', null, null);
    }

    /** Without a session both operations answer the unauthenticated problem. */
    public function test_it_requires_a_session(): void
    {
        $this->getJson($this->url($this->puraNavaja))->assertUnauthorized()->assertJsonPath('type', $this->problem('no-autenticado'));
        $this->patchJson($this->url($this->puraNavaja), ['ciudad' => 'Fortuna'])->assertUnauthorized();
    }

    /** A barbershop with no optional data shows every field of the form, empty. */
    public function test_a_new_barbershop_has_no_optional_data(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->getJson($this->url($this->puraNavaja))->assertOk()->assertExactJson(['data' => [
            'id' => $this->puraNavaja,
            'nombre' => 'Barbería Pura Navaja',
            'ciudad' => 'San Carlos',
            'telefono' => '2460-0000',
            'enlace' => 'pura-navaja',
            'direccion' => null,
            'correo_contacto' => null,
            'redes' => ['whatsapp' => null, 'instagram' => null, 'facebook' => null, 'tiktok' => null],
        ]]);
    }

    /** The owner edits everything and the public page shows it (RF-02). */
    public function test_owner_edits_and_the_public_page_shows_it(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), [
            'nombre' => 'Pura Navaja Studio',
            'ciudad' => 'Ciudad Quesada',
            'telefono' => '2460-1111',
            'direccion' => '100 m norte de la iglesia',
            'correo_contacto' => 'contacto@puranavaja.test',
            'redes' => [
                'whatsapp' => 'https://wa.me/50600000001',
                'instagram' => 'https://www.instagram.com/puranavaja',
                'facebook' => 'https://fb.com/puranavaja',
                'tiktok' => 'https://tiktok.com/@puranavaja',
            ],
        ])->assertOk()->assertJsonPath('data.nombre', 'Pura Navaja Studio')->assertJsonPath('data.redes.facebook', 'https://fb.com/puranavaja');

        $this->getJson('/api/v1/enlaces/pura-navaja')->assertOk()->assertExactJson(['data' => [
            'id' => $this->puraNavaja,
            'nombre' => 'Pura Navaja Studio',
            'ciudad' => 'Ciudad Quesada',
            'telefono' => '2460-1111',
            'direccion' => '100 m norte de la iglesia',
            'correo_contacto' => 'contacto@puranavaja.test',
            'redes' => [
                'whatsapp' => 'https://wa.me/50600000001',
                'instagram' => 'https://www.instagram.com/puranavaja',
                'facebook' => 'https://fb.com/puranavaja',
                'tiktok' => 'https://tiktok.com/@puranavaja',
            ],
        ]]);
    }

    /** The public page never returns an empty datum, and a barbershop without any optional data still answers (RF-02). */
    public function test_the_public_page_leaves_out_what_is_empty(): void
    {
        $this->getJson('/api/v1/enlaces/pura-navaja')->assertOk()->assertExactJson(['data' => [
            'id' => $this->puraNavaja,
            'nombre' => 'Barbería Pura Navaja',
            'ciudad' => 'San Carlos',
            'telefono' => '2460-0000',
        ]]);

        Sanctum::actingAs($this->owner, []);
        $this->patchJson($this->url($this->puraNavaja), ['redes' => ['instagram' => 'https://instagram.com/puranavaja']])->assertOk();

        $this->getJson('/api/v1/enlaces/pura-navaja')->assertOk()->assertExactJson(['data' => [
            'id' => $this->puraNavaja,
            'nombre' => 'Barbería Pura Navaja',
            'ciudad' => 'San Carlos',
            'telefono' => '2460-0000',
            'redes' => ['instagram' => 'https://instagram.com/puranavaja'],
        ]]);
    }

    /** The public page shows nothing of the owner's account, and the contact email is not filled with the account's one. */
    public function test_the_public_page_never_shows_the_account_of_the_owner(): void
    {
        Sanctum::actingAs($this->owner, []);
        $this->patchJson($this->url($this->puraNavaja), ['direccion' => 'Calle 1'])->assertOk()->assertJsonPath('data.correo_contacto', null);

        $body = (string) $this->getJson('/api/v1/enlaces/pura-navaja')->assertOk()->assertJsonMissingPath('data.correo_contacto')->getContent();

        $this->assertStringNotContainsString('ana@barberhub.test', $body);
    }

    /** A field that does not come keeps its value. */
    public function test_a_partial_change_keeps_the_rest(): void
    {
        Sanctum::actingAs($this->owner, []);
        $this->patchJson($this->url($this->puraNavaja), ['direccion' => 'Calle 1', 'redes' => ['instagram' => 'https://instagram.com/a', 'tiktok' => 'https://tiktok.com/@a']])->assertOk();

        $this->patchJson($this->url($this->puraNavaja), ['redes' => ['instagram' => 'https://instagram.com/b']])->assertOk()
            ->assertJsonPath('data.direccion', 'Calle 1')
            ->assertJsonPath('data.redes.instagram', 'https://instagram.com/b')
            ->assertJsonPath('data.redes.tiktok', 'https://tiktok.com/@a')
            ->assertJsonPath('data.nombre', 'Barbería Pura Navaja');
    }

    /** An empty text or null removes the datum. */
    #[DataProvider('emptyValues')]
    public function test_emptying_a_datum_removes_it(?string $empty): void
    {
        Sanctum::actingAs($this->owner, []);
        $this->patchJson($this->url($this->puraNavaja), [
            'direccion' => 'Calle 1',
            'correo_contacto' => 'contacto@puranavaja.test',
            'redes' => ['whatsapp' => 'https://wa.me/1', 'instagram' => 'https://instagram.com/a', 'facebook' => 'https://facebook.com/a', 'tiktok' => 'https://tiktok.com/@a'],
        ])->assertOk();

        $this->patchJson($this->url($this->puraNavaja), [
            'direccion' => $empty,
            'correo_contacto' => $empty,
            'redes' => ['whatsapp' => $empty, 'instagram' => $empty, 'facebook' => $empty, 'tiktok' => $empty],
        ])->assertOk()->assertJsonPath('data.direccion', null)->assertJsonPath('data.correo_contacto', null)
            ->assertJsonPath('data.redes', ['whatsapp' => null, 'instagram' => null, 'facebook' => null, 'tiktok' => null]);

        $this->getJson('/api/v1/enlaces/pura-navaja')->assertOk()->assertExactJson(['data' => [
            'id' => $this->puraNavaja,
            'nombre' => 'Barbería Pura Navaja',
            'ciudad' => 'San Carlos',
            'telefono' => '2460-0000',
        ]]);
        $this->assertSame([null, null, null, null, null, null], $this->storedOptionalColumns($this->puraNavaja));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function emptyValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty text' => [''];
    }

    /** Renaming the barbershop changes its name but not its public link (MT-07, RF-02). */
    public function test_renaming_does_not_change_the_public_link(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), ['nombre' => 'La Navaja Dorada'])->assertOk()->assertJsonPath('data.enlace', 'pura-navaja');

        $this->getJson('/api/v1/enlaces/pura-navaja')->assertOk()->assertJsonPath('data.nombre', 'La Navaja Dorada');
        $this->getJson('/api/v1/enlaces/la-navaja-dorada')->assertNotFound();
        $this->assertSame('pura-navaja', DB::table('barbershops')->where('id', $this->puraNavaja)->value('public_link'));
    }

    /** The change of one barbershop does not reach the other. */
    public function test_the_change_does_not_reach_the_other_barbershop(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), ['direccion' => 'Calle 1', 'redes' => ['instagram' => 'https://instagram.com/a']])->assertOk();

        $this->assertSame([null, null, null, null, null, null], $this->storedOptionalColumns($this->fadeLab));
    }

    /**
     * Each network rejects a link of another domain, http://, and the dangerous schemes, and says which field failed (RF-01).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function invalidLinks(): iterable
    {
        $valid = ['whatsapp' => 'https://wa.me/1', 'instagram' => 'https://instagram.com/a', 'facebook' => 'https://facebook.com/a', 'tiktok' => 'https://tiktok.com/@a'];

        foreach ($valid as $network => $url) {
            $other = $network === 'instagram' ? 'https://facebook.com/a' : 'https://instagram.com/a';

            yield "$network of another network" => [$network, $other];
            yield "$network with http" => [$network, 'http://'.substr($url, 8)];
            yield "$network with javascript" => [$network, 'javascript:alert(1)'];
            yield "$network with data" => [$network, 'data:text/html;base64,PHNjcmlwdD4='];
            yield "$network with file" => [$network, 'file:///etc/passwd'];
            yield "$network without scheme" => [$network, substr($url, 8)];
            yield "$network with a space" => [$network, $url.' x'];
            yield "$network with a line break" => [$network, $url."\nhttps://evil.test"];
            yield "$network on a lookalike domain" => [$network, 'https://evil-'.substr($url, 8)];
            yield "$network on a subdomain of another site" => [$network, 'https://'.explode('/', substr($url, 8))[0].'.evil.test/a'];
            yield "$network with credentials" => [$network, 'https://user:pass@'.substr($url, 8)];
            yield "$network too long" => [$network, $url.'/'.str_repeat('a', 300)];
        }

        yield 'facebook on an unrelated whatsapp domain' => ['facebook', 'https://wa.me/1'];
    }

    /** A link outside the rules answers 422 with the field `redes.<red>` and stores nothing. */
    #[DataProvider('invalidLinks')]
    public function test_an_invalid_link_is_rejected(string $network, string $url): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), ['direccion' => 'Calle 1', 'redes' => [$network => $url]])
            ->assertUnprocessable()
            ->assertJsonPath('type', $this->problem('datos-invalidos'))
            ->assertJsonValidationErrors("redes.$network", 'errores');

        $this->assertSame([null, null, null, null, null, null], $this->storedOptionalColumns($this->puraNavaja));
    }

    /** The accepted domains of each network, with and without www. */
    #[DataProvider('validLinks')]
    public function test_a_link_of_its_network_is_accepted(string $network, string $url): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), ['redes' => [$network => $url]])->assertOk()->assertJsonPath("data.redes.$network", $url);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validLinks(): iterable
    {
        yield 'wa.me' => ['whatsapp', 'https://wa.me/50600000001'];
        yield 'api.whatsapp.com' => ['whatsapp', 'https://api.whatsapp.com/send?phone=50600000001'];
        yield 'instagram' => ['instagram', 'https://instagram.com/puranavaja'];
        yield 'instagram with www' => ['instagram', 'https://www.instagram.com/puranavaja'];
        yield 'facebook.com' => ['facebook', 'https://facebook.com/puranavaja'];
        yield 'fb.com' => ['facebook', 'https://fb.com/puranavaja'];
        yield 'tiktok' => ['tiktok', 'https://tiktok.com/@puranavaja'];
        yield 'tiktok with www' => ['tiktok', 'https://www.tiktok.com/@puranavaja'];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidFields(): iterable
    {
        yield 'invalid email' => [['correo_contacto' => 'no-es-un-correo'], 'correo_contacto'];
        yield 'email without domain' => [['correo_contacto' => 'ana@'], 'correo_contacto'];
        yield 'email too long' => [['correo_contacto' => str_repeat('a', 140).'@puranavaja.test'], 'correo_contacto'];
        yield 'address too long' => [['direccion' => str_repeat('a', 201)], 'direccion'];
        yield 'address with a line break' => [['direccion' => "Calle 1\nCalle 2"], 'direccion'];
        yield 'empty name' => [['nombre' => ''], 'nombre'];
        yield 'name too long' => [['nombre' => str_repeat('a', 121)], 'nombre'];
        yield 'unknown network' => [['redes' => ['myspace' => 'https://myspace.com/a']], 'redes'];
        yield 'networks as text' => [['redes' => 'https://instagram.com/a'], 'redes'];
    }

    /**
     * Any other invalid datum answers 422 with its field and stores nothing.
     *
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidFields')]
    public function test_an_invalid_field_is_rejected(array $payload, string $field): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('type', $this->problem('datos-invalidos'))
            ->assertJsonValidationErrors($field, 'errores');

        $this->assertSame([null, null, null, null, null, null], $this->storedOptionalColumns($this->puraNavaja));
    }

    /** Only the owner reads or changes it: a collaborator and a customer get 403, a stranger and another barbershop's owner get the 404 of a missing barbershop. */
    public function test_only_the_owner_can_use_it(): void
    {
        foreach ([$this->collaborator, $this->customer] as $member) {
            Sanctum::actingAs($member, []);

            $this->getJson($this->url($this->puraNavaja))->assertForbidden()->assertJsonPath('type', $this->problem('prohibido'));
            $this->patchJson($this->url($this->puraNavaja), ['direccion' => 'Calle 1'])->assertForbidden()->assertJsonPath('type', $this->problem('prohibido'));
        }

        $missing = (string) Str::uuid7();
        foreach ([$this->stranger, $this->otherOwner] as $outsider) {
            Sanctum::actingAs($outsider, []);

            foreach (['getJson' => [], 'patchJson' => ['direccion' => 'Calle 1']] as $method => $payload) {
                $foreign = $this->{$method}($this->url($this->puraNavaja), $payload)->assertNotFound();
                $nobody = $this->{$method}($this->url($missing), $payload)->assertNotFound();
                $this->assertSame($this->withoutInstance($nobody->json()), $this->withoutInstance($foreign->json()));
            }
        }

        $this->assertSame([null, null, null, null, null, null], $this->storedOptionalColumns($this->puraNavaja));
        $this->assertSame('Barbería Pura Navaja', DB::table('barbershops')->where('id', $this->puraNavaja)->value('name'));
    }

    /** The database rejects a link that does not start with https://, even if a statement skips the application. */
    #[DataProvider('columns')]
    public function test_the_database_rejects_a_link_without_https(string $column): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("barbershops_{$column}_check");

        DB::table('barbershops')->where('id', $this->puraNavaja)->update([$column => 'http://example.test/a']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function columns(): iterable
    {
        foreach (['whatsapp_url', 'instagram_url', 'facebook_url', 'tiktok_url'] as $column) {
            yield $column => [$column];
        }
    }

    /** Address of the data of a barbershop. */
    private function url(string $barbershopId): string
    {
        return "/api/v1/barberias/{$barbershopId}/administracion/negocio";
    }

    /** Type of a problem as the API publishes it. */
    private function problem(string $type): string
    {
        return rtrim((string) config('app.url'), '/').'/problemas/'.$type;
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

    /**
     * The optional columns of a barbershop as they are stored: address, contact email and the four links.
     *
     * @return list<string|null>
     */
    private function storedOptionalColumns(string $barbershopId): array
    {
        $row = DB::table('barbershops')->where('id', $barbershopId)->first();
        $this->assertNotNull($row);

        return [$row->address, $row->contact_email, $row->whatsapp_url, $row->instagram_url, $row->facebook_url, $row->tiktok_url];
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function createBarbershop(string $name, string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $name, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

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
