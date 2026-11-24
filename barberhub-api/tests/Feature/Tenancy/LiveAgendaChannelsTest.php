<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Tenancy\AccessMatrix;
use Tests\Support\Tenancy\BuildsAccessScenario;
use Tests\TestCase;

/**
 * Who may listen to the live agenda (RF-36, NO-06, CO-04, MT-02, UP-02, UP-06): the eight people of the permission matrix ask to subscribe to
 * the channel of the agenda of Pura Navaja and to the one of its collaborator Daniela, with the real route and a real token. The channels are
 * checked with the signing of the `reverb` connection (fake keys, no network), because the `null` one the other tests use authorizes nothing.
 */
final class LiveAgendaChannelsTest extends TestCase
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

    /**
     * What each person gets in each channel, in the order of AccessMatrix::ACTORS. The owner hears the whole agenda in the channel of the
     * barbershop and none of a collaborator; the collaborator that holds the agenda hears only their own (CO-04).
     *
     * @return array<string, array{string, list<int>}>
     */
    public static function channelOutcomes(): array
    {
        return [
            'agenda of the barbershop' => ['agenda', [401, 403, 403, 403, 403, 200, 404, 404]],
            'agenda of Daniela' => ['collaborator', [401, 403, 403, 404, 200, 403, 404, 404]],
        ];
    }

    /** @param  list<int>  $outcomes */
    #[DataProvider('channelOutcomes')]
    public function test_each_person_gets_the_answer_of_the_channel(string $channel, array $outcomes): void
    {
        $name = $this->channelOf($channel, $this->puraNavaja, $this->daniela);

        foreach (AccessMatrix::ACTORS as $position => $actor) {
            $response = $this->authorizeAs($actor, $name);

            $this->assertSame($outcomes[$position], $response->getStatusCode(), "{$name} as {$actor}");

            if ($outcomes[$position] === 200) {
                $this->assertStringStartsWith(self::KEY.':', (string) $response->json('auth'), "{$name} as {$actor} gets no signature");
            } else {
                $this->assertArrayNotHasKey('auth', (array) $response->json(), "{$name} as {$actor}: a refusal carries a signature");
            }
        }
    }

    /** Every 404 reads like the one of a barbershop that does not exist, and none names the channel, the barbershop or the collaborator (UP-06, MT-03). */
    public function test_every_not_found_is_the_same_and_gives_nothing_away(): void
    {
        $missing = $this->problemOf($this->authorizeAs('owner', $this->channelOf('agenda', (string) Str::uuid7(), $this->daniela)));
        $this->assertSame(404, $this->authorizeAs('owner', $this->channelOf('agenda', (string) Str::uuid7(), $this->daniela))->getStatusCode());

        $cases = [
            'owner of another barbershop, agenda' => ['foreign_owner', $this->channelOf('agenda', $this->puraNavaja, $this->daniela)],
            'owner of another barbershop, collaborator' => ['foreign_owner', $this->channelOf('collaborator', $this->puraNavaja, $this->daniela)],
            'collaborator of another barbershop, agenda' => ['foreign_collaborator', $this->channelOf('agenda', $this->puraNavaja, $this->daniela)],
            'collaborator of another barbershop with their own profile' => ['foreign_collaborator', $this->channelOf('collaborator', $this->puraNavaja, $this->mauricio)],
            'collaborator, the agenda of a colleague' => ['collaborator', $this->channelOf('collaborator', $this->puraNavaja, $this->daniela)],
            'collaborator, a profile that does not exist' => ['collaborator', $this->channelOf('collaborator', $this->puraNavaja, (string) Str::uuid7())],
            'collaborator, an identifier that is not one' => ['assigned_collaborator', 'private-barberia.'.$this->puraNavaja.'.colaboradores.no-es-un-uuid'],
            'owner, a barbershop that is not an identifier' => ['owner', 'private-barberia.no-es-un-uuid.agenda'],
        ];

        foreach ($cases as $label => [$actor, $channel]) {
            $response = $this->authorizeAs($actor, $channel);

            $this->assertSame(404, $response->getStatusCode(), $label);
            $this->assertSame($missing, $this->problemOf($response), "{$label}: the 404 differs from the one of a missing barbershop");

            foreach ([$this->puraNavaja, $this->daniela, $this->kevin, $this->mauricio, 'Pura Navaja'] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $response->getContent(), "{$label}: the refusal gives away {$secret}");
            }
        }
    }

    /** The refusals of a person of the barbershop are all the same 403 `prohibido`, without a signature. */
    public function test_every_forbidden_is_the_same(): void
    {
        $cases = [
            ['customer', $this->channelOf('agenda', $this->puraNavaja, $this->daniela)],
            ['collaborator', $this->channelOf('agenda', $this->puraNavaja, $this->daniela)],
            ['owner', $this->channelOf('collaborator', $this->puraNavaja, $this->daniela)],
            ['other_customer', $this->channelOf('collaborator', $this->puraNavaja, $this->daniela)],
        ];
        $problems = [];

        foreach ($cases as [$actor, $channel]) {
            $response = $this->authorizeAs($actor, $channel);
            $response->assertForbidden()->assertJsonPath('type', 'http://localhost/problemas/prohibido');
            $problems[] = $this->problemOf($response);
        }

        $this->assertCount(1, array_unique(array_map('json_encode', $problems)));
    }

    /** A person of Fade Lab reaches the channels of their own barbershop and only of it (MT-02). */
    public function test_the_other_barbershop_has_its_own_channels(): void
    {
        $this->authorizeAs('foreign_owner', $this->channelOf('agenda', $this->fadeLab, $this->mauricio))->assertOk();
        $this->authorizeAs('foreign_collaborator', $this->channelOf('collaborator', $this->fadeLab, $this->mauricio))->assertOk();
        $this->authorizeAs('owner', $this->channelOf('agenda', $this->fadeLab, $this->mauricio))->assertNotFound();
        $this->authorizeAs('assigned_collaborator', $this->channelOf('collaborator', $this->fadeLab, $this->mauricio))->assertNotFound();
    }

    /** The identifiers are read in any case, as the routes do. */
    public function test_the_identifiers_may_come_in_capitals(): void
    {
        $this->authorizeAs('owner', 'private-barberia.'.strtoupper($this->puraNavaja).'.agenda')->assertOk();
        $this->authorizeAs('assigned_collaborator', 'private-barberia.'.strtoupper($this->puraNavaja).'.colaboradores.'.strtoupper($this->daniela))->assertOk();
    }

    /** A person with a temporary password cannot subscribe: the route is one that asks for a session (UP-04). */
    public function test_a_temporary_password_is_refused(): void
    {
        DB::table('users')->where('id', $this->owner)->update(['must_change_password' => true]);

        $this->authorizeAs('owner', $this->channelOf('agenda', $this->puraNavaja, $this->daniela))
            ->assertForbidden()
            ->assertJsonPath('type', 'http://localhost/problemas/contrasena-temporal');
    }

    /** A name that is no channel of the agenda is refused and signs nothing; one that is not private is no subscription to authorize. */
    public function test_an_unknown_channel_is_refused(): void
    {
        foreach (['private-barberia.'.$this->puraNavaja.'.ingresos', 'private-usuario.'.$this->ana, 'private-barberia.'.$this->puraNavaja.'.agenda.extra'] as $channel) {
            $response = $this->authorizeAs('owner', $channel);

            $this->assertSame(403, $response->getStatusCode(), $channel);
            $this->assertArrayNotHasKey('auth', (array) $response->json(), $channel);
        }

        $this->authorizeAs('owner', 'barberia.'.$this->puraNavaja.'.agenda')->assertUnprocessable();
    }

    /** The body must carry the connection and the channel, in the form the client library sends them. */
    public function test_the_body_is_validated(): void
    {
        $this->sendAs('owner', 'POST', self::ROUTE, [])->assertUnprocessable();
        $this->sendAs('owner', 'POST', self::ROUTE, ['socket_id' => 'no-es-un-socket', 'channel_name' => 'private-barberia.x.agenda'])->assertUnprocessable();
        $this->sendAs('owner', 'POST', self::ROUTE, ['socket_id' => '123.456'])->assertUnprocessable();
    }

    /** Authorizing again and again is limited to 60 a minute per person, so a screen that reconnects in a loop cannot flood the API. */
    public function test_the_authorizations_are_limited_per_person(): void
    {
        $name = $this->channelOf('agenda', $this->puraNavaja, $this->daniela);

        foreach (range(1, 60) as $attempt) {
            $this->assertSame(200, $this->authorizeAs('owner', $name)->getStatusCode(), "attempt {$attempt}");
        }

        $this->authorizeAs('owner', $name)->assertTooManyRequests();
        $this->authorizeAs('foreign_owner', $this->channelOf('agenda', $this->fadeLab, $this->mauricio))->assertOk();
    }

    /** The name of a private channel of the agenda of a barbershop or of a collaborator. */
    private function channelOf(string $kind, string $barbershopId, string $collaboratorId): string
    {
        return $kind === 'agenda'
            ? "private-barberia.{$barbershopId}.agenda"
            : "private-barberia.{$barbershopId}.colaboradores.{$collaboratorId}";
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
