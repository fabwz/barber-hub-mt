<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Tenancy\AccessMatrix;
use Tests\Support\Tenancy\BuildsAccessScenario;
use Tests\TestCase;

/**
 * Who may listen to the channel of a person, where the offers of the waitlist reach them (RF-35, NO-08, UP-06): the eight people of the permission
 * matrix ask to subscribe to their own channel and to the ones of the others, with the real route and a real token. The channels are checked with the
 * signing of the `reverb` connection (fake keys, no network), because the `null` one the other tests use authorizes nothing.
 */
final class LiveUserChannelTest extends TestCase
{
    use BuildsAccessScenario;
    use RefreshDatabaseAsOwner;

    private const string ROUTE = '/api/v1/tiempo-real/autorizacion';

    private const string KEY = 'llave-de-prueba';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => self::KEY,
            'broadcasting.connections.reverb.secret' => 'secreto-de-prueba',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
        ]);
        $this->buildAccessScenario();
    }

    /** Everybody with a session hears their own channel, whatever their role, and a request without a session is refused (401). */
    public function test_each_person_hears_their_own_channel(): void
    {
        foreach (AccessMatrix::ACTORS as $actor) {
            if ($actor === 'anonymous') {
                $this->authorizeAs($actor, 'private-usuario.'.$this->ana)->assertUnauthorized();

                continue;
            }

            $response = $this->authorizeAs($actor, 'private-usuario.'.$this->accounts[$actor])->assertOk();
            $this->assertStringStartsWith(self::KEY.':', (string) $response->json('auth'), "{$actor} gets no signature");
        }
    }

    /** Nobody hears the channel of another person: every attempt is the 404 of a missing resource, with no signature and nothing given away (UP-06, MT-03). */
    public function test_nobody_hears_the_channel_of_another_person(): void
    {
        $missing = $this->problemOf($this->authorizeAs('customer', 'private-usuario.'.Str::uuid7()));
        $this->assertSame(404, $this->authorizeAs('customer', 'private-usuario.'.Str::uuid7())->getStatusCode());

        foreach (array_diff(AccessMatrix::ACTORS, ['anonymous']) as $actor) {
            foreach ($this->accounts as $owner => $account) {
                if ($owner === $actor) {
                    continue;
                }

                $response = $this->authorizeAs($actor, 'private-usuario.'.$account);

                $this->assertSame(404, $response->getStatusCode(), "{$actor} on the channel of {$owner}");
                $this->assertSame($missing, $this->problemOf($response), "{$actor} on the channel of {$owner}: the 404 differs from the one of a missing account");
                $this->assertArrayNotHasKey('auth', (array) $response->json());
                $this->assertStringNotContainsString($account, (string) $response->getContent());
            }
        }
    }

    /** An account that does not exist and a name that is not an identifier read the same, so nobody can probe for accounts. */
    public function test_a_channel_that_is_no_account_reads_as_missing(): void
    {
        $missing = $this->problemOf($this->authorizeAs('customer', 'private-usuario.'.Str::uuid7()));

        foreach (['private-usuario.no-es-un-uuid', 'private-usuario.1'] as $channel) {
            $response = $this->authorizeAs('customer', $channel);

            $this->assertSame(404, $response->getStatusCode(), $channel);
            $this->assertSame($missing, $this->problemOf($response), $channel);
        }
    }

    /** The identifier is read in any case, as the routes do. */
    public function test_the_identifier_may_come_in_capitals(): void
    {
        $this->authorizeAs('customer', 'private-usuario.'.strtoupper($this->ana))->assertOk();
    }

    /** A person with a temporary password cannot subscribe, not even to their own channel (UP-04). */
    public function test_a_temporary_password_is_refused(): void
    {
        DB::table('users')->where('id', $this->ana)->update(['must_change_password' => true]);

        $this->authorizeAs('customer', 'private-usuario.'.$this->ana)
            ->assertForbidden()
            ->assertJsonPath('type', 'http://localhost/problemas/contrasena-temporal');
    }

    /** A channel of a person with more levels is no channel: it is refused and signs nothing. */
    public function test_a_longer_name_is_no_channel(): void
    {
        $response = $this->authorizeAs('customer', 'private-usuario.'.$this->ana.'.ofertas');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertArrayNotHasKey('auth', (array) $response->json());
    }

    /**
     * Ask to subscribe as one of the people of the matrix.
     *
     * @return TestResponse<Response>
     */
    private function authorizeAs(string $actor, string $channel)
    {
        return $this->sendAs($actor, 'POST', self::ROUTE, ['socket_id' => '123.456', 'channel_name' => $channel]);
    }
}
