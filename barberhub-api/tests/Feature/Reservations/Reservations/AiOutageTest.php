<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\Payments\SimulatedPaymentGateway;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Shared\MutableClock;
use Tests\TestCase;

/**
 * The system keeps working when the language provider fails or its free quota is over (IA-05, RNF-11): through the real adapter and
 * the circuit breaker, with the provider simulated by the HTTP client (nothing calls Gemini). The 503 invites the normal form and
 * carries Retry-After; booking, availability, payment and the health route do not notice.
 */
final class AiOutageTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;

    private const string PHRASE = 'frase-que-no-debe-estar-en-los-registros';

    private const string KEY = 'llave-de-prueba-que-no-debe-estar-en-los-registros';

    private const string PROVIDER = 'generativelanguage.googleapis.com/*';

    private MutableClock $clock;

    private string $ana;

    /** @var list<MessageLogged> */
    private array $logs = [];

    /** A configured provider, a clock that only moves when the test says, no request that is not simulated and an account without roles. */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['ai.gemini.api_key' => self::KEY, 'ai.gemini.model' => 'test-model']);
        $this->seedBookableBarbershops();
        $this->clock = new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica')));
        $this->app->instance(Clock::class, $this->clock);
        $this->ana = $this->insertUser('Ana Rojas');
        $this->actAs($this->ana);
        Event::listen(MessageLogged::class, function (MessageLogged $log): void {
            $this->logs[] = $log;
        });
    }

    /** Three failures open the circuit; the fourth proposal is answered at once with 503, Retry-After and the invitation to book normally, without touching the provider. */
    public function test_the_open_circuit_answers_503_with_retry_after_without_calling_the_provider(): void
    {
        Http::fake([self::PROVIDER => Http::response(['error' => 'down'], 500)]);

        $this->propose()->assertStatus(503)->assertHeaderMissing('Retry-After');
        $this->propose()->assertStatus(503)->assertHeaderMissing('Retry-After');
        $this->propose()->assertStatus(503)->assertHeader('Retry-After', '60');
        Http::assertSentCount(3);

        $this->clock->moveTo($this->clock->now()->modify('+15 seconds'));
        $this->assertDegraded($this->propose(), '45');
        Http::assertSentCount(3);
    }

    /** After the open time one probe goes through: a success closes the circuit and the proposals work again. */
    public function test_the_circuit_closes_by_itself_after_a_successful_probe(): void
    {
        Http::fake([self::PROVIDER => Http::sequence()
            ->push(['error' => 'down'], 500)->push(['error' => 'down'], 500)->push(['error' => 'down'], 500)
            ->push($this->answer(['service' => 'corte']))->push($this->answer(['service' => 'corte']))]);

        foreach (range(1, 3) as $ignored) {
            $this->propose()->assertStatus(503);
        }

        $this->clock->moveTo($this->clock->now()->modify('+60 seconds'));
        $this->propose()->assertOk();
        $this->propose()->assertOk();
        Http::assertSentCount(5);
    }

    /** A failed probe opens the circuit again. */
    public function test_a_failed_probe_opens_the_circuit_again(): void
    {
        Http::fake([self::PROVIDER => Http::response(['error' => 'down'], 503)]);

        foreach (range(1, 3) as $ignored) {
            $this->propose()->assertStatus(503);
        }

        $this->clock->moveTo($this->clock->now()->modify('+60 seconds'));
        $this->assertDegraded($this->propose(), '60');
        $this->assertDegraded($this->propose(), '60');
        Http::assertSentCount(4);
    }

    /** A 429 of the provider with its own Retry-After is respected from the first answer, and the open circuit keeps it as long as the provider asked. */
    public function test_a_429_with_retry_after_of_the_provider_is_respected(): void
    {
        Http::fake([self::PROVIDER => Http::response(['error' => 'quota'], 429, ['Retry-After' => '120'])]);

        $this->assertDegraded($this->propose(), '120');
        $this->assertDegraded($this->propose(), '120');
        $this->assertDegraded($this->propose(), '120');
        Http::assertSentCount(3);

        $this->clock->moveTo($this->clock->now()->modify('+30 seconds'));
        $this->assertDegraded($this->propose(), '90');
        Http::assertSentCount(3);
    }

    /** Refused credentials (401) open the circuit at once, as critical, and the next proposal does not call the provider. */
    public function test_refused_credentials_open_the_circuit_at_once(): void
    {
        Http::fake([self::PROVIDER => Http::response(['error' => 'bad key'], 401)]);

        $this->assertDegraded($this->propose(), '60');
        $this->assertDegraded($this->propose(), '60');
        Http::assertSentCount(1);
        $this->assertContains('critical', array_map(fn (MessageLogged $log): string => $log->level, $this->logs));
    }

    /** An invalid answer of the model is a 503 that invites the normal form, but it never opens the circuit. */
    public function test_an_invalid_answer_gives_503_but_does_not_open_the_circuit(): void
    {
        Http::fake([self::PROVIDER => Http::response(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'not json']]], 'finishReason' => 'STOP']]])]);

        foreach (range(1, 5) as $ignored) {
            $this->propose()->assertStatus(503)->assertHeaderMissing('Retry-After');
        }

        Http::assertSentCount(5);
    }

    /** If the cache of the circuit is down the proposal is still tried and fails as 503, never as 500. */
    public function test_a_cache_that_is_down_never_turns_the_proposal_into_a_500(): void
    {
        config(['ai.circuit.store' => 'not-a-store']);
        Http::fake([self::PROVIDER => Http::response(['error' => 'down'], 500)]);

        foreach (range(1, 5) as $ignored) {
            $this->propose()->assertStatus(503)->assertHeaderMissing('Retry-After');
        }

        Http::assertSentCount(5);
    }

    /** Neither the phrase nor the key reach a log, through the adapter and the circuit, in success or failure. */
    public function test_the_logs_never_have_the_phrase_or_the_key(): void
    {
        Http::fake([self::PROVIDER => Http::sequence()
            ->push(['error' => 'down'], 500)->push(['error' => 'down'], 500)->push(['error' => 'down'], 500)
            ->push(['error' => 'bad key'], 401)->push($this->answer(['service' => 'corte']))]);

        foreach (range(1, 4) as $ignored) {
            $this->propose();
        }
        $this->clock->moveTo($this->clock->now()->modify('+60 seconds'));
        $this->propose();

        $logs = (string) json_encode(array_map(fn (MessageLogged $log): array => [$log->message, $log->context], $this->logs));
        $this->assertStringContainsString('The AI circuit changed', $logs);
        $this->assertStringNotContainsString(self::PHRASE, $logs);
        $this->assertStringNotContainsString(self::KEY, $logs);
    }

    /** While the circuit is open, booking, availability, payment and the health route work as always, and nothing calls the provider. */
    public function test_the_rest_of_the_system_works_while_the_circuit_is_open(): void
    {
        $gateway = new SimulatedPaymentGateway(PaymentProvider::Onvo, [PaymentMethod::Card]);
        $this->app->instance('tests.gateway.onvo', $gateway);
        config(['payments.gateways' => ['onvo' => 'tests.gateway.onvo']]);
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'deposit', 'deposit_percentage' => 30, 'payment_gateway' => 'onvo']);

        Http::fake([self::PROVIDER => Http::response(['error' => 'down'], 500)]);
        foreach (range(1, 3) as $ignored) {
            $this->propose()->assertStatus(503);
        }
        $this->assertDegraded($this->propose(), '60');
        Http::assertSentCount(3);

        $reservation = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->json('data.id');
        $this->getJson("/api/v1/barberias/{$this->puraNavaja}/disponibilidad?".http_build_query(['servicio' => $this->degradado, 'fecha' => '2030-03-05']))->assertOk();
        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$reservation}/pagos", ['metodo' => 'tarjeta', 'token' => 'pm_test_1'])
            ->assertCreated()->assertJsonPath('data.estado', 'aprobado');
        $this->getJson('/salud')->assertOk()->assertExactJson(['estado' => 'ok', 'ia' => 'degradada']);
        Http::assertSentCount(3);
    }

    /**
     * The 503 that invites the normal form, with the seconds of Retry-After that are left (null: the header may be absent).
     *
     * @param  TestResponse<JsonResponse>  $response
     * @return TestResponse<JsonResponse>
     */
    private function assertDegraded(TestResponse $response, ?string $retryAfter): TestResponse
    {
        $response->assertStatus(503)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', rtrim((string) config('app.url'), '/').'/problemas/servicio-no-disponible')
            ->assertJsonPath('status', 503)
            ->assertJsonPath('detail', 'El asistente de reservas no está disponible en este momento. Puede reservar con el formulario normal.');

        if ($retryAfter !== null) {
            $response->assertHeader('Retry-After', $retryAfter);
        }

        return $response;
    }

    /**
     * What Gemini answers when the model gives this JSON.
     *
     * @param  array<string, string>  $extracted
     * @return array<string, mixed>
     */
    private function answer(array $extracted): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => json_encode($extracted, JSON_THROW_ON_ERROR)]]], 'finishReason' => 'STOP']]];
    }

    /**
     * Send the phrase to the proposals of Pura Navaja as Ana.
     *
     * @return TestResponse<JsonResponse>
     */
    private function propose(): TestResponse
    {
        return $this->postJson($this->reservationsUrl($this->puraNavaja).'/propuesta', ['frase' => self::PHRASE]);
    }
}
