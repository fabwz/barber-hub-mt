<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Src\Reservations\Reservations\Application\DTOs\ExtractedReservation;
use Src\Reservations\Reservations\Application\DTOs\ExtractionContext;
use Src\Reservations\Reservations\Application\Exceptions\ExtractionInvalidException;
use Src\Reservations\Reservations\Application\Exceptions\ExtractorFailure;
use Src\Reservations\Reservations\Application\Exceptions\ExtractorMisconfiguredException;
use Src\Reservations\Reservations\Application\Exceptions\ExtractorUnavailableException;
use Src\Reservations\Reservations\Infrastructure\Ai\AiCircuitStore;
use Src\Reservations\Reservations\Infrastructure\Ai\CircuitBreakerReservationExtractor;
use Tests\Support\Reservations\ScriptedReservationExtractor;
use Tests\Support\Shared\MutableClock;
use Tests\TestCase;

/**
 * The circuit breaker around the language provider (IA-05, RNF-11): 3 failures of availability in 60 s open it for 60 s, one probe
 * decides, and nothing here calls Gemini: the extractor behind it is scripted and the clock is fixed.
 */
final class AiCircuitBreakerTest extends TestCase
{
    private const string PHRASE = 'frase-que-no-debe-estar-en-los-registros';

    private ScriptedReservationExtractor $provider;

    private MutableClock $clock;

    private AiCircuitStore $store;

    /** @var list<MessageLogged> */
    private array $logs = [];

    /** A circuit of 3 failures, a 60 s window and 60 s open, over an in-memory cache and a clock that only moves when the test says. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new ScriptedReservationExtractor;
        $this->clock = new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica')));
        $this->store = new AiCircuitStore($this->app->make(Factory::class), 'array', 'gemini');
        Event::listen(MessageLogged::class, function (MessageLogged $log): void {
            $this->logs[] = $log;
        });
    }

    /** Three failures in a row open the circuit; from then on the provider is not called and the answer carries the seconds left. */
    public function test_consecutive_failures_open_the_circuit_and_the_next_calls_do_not_reach_the_provider(): void
    {
        $this->provider->will(ExtractorUnavailableException::because('timeout'));
        $circuit = $this->circuit();

        $this->assertOutage($circuit, null);
        $this->assertOutage($circuit, null);
        $this->assertOutage($circuit, 60);
        $this->assertSame(3, $this->provider->calls);

        $this->clock->moveTo($this->clock->now()->modify('+20 seconds'));

        $this->assertOutage($circuit, 40);
        $this->assertOutage($circuit, 40);
        $this->assertSame(3, $this->provider->calls, 'An open circuit must not call the provider.');
    }

    /** Failures farther apart than the window are not consecutive: the count starts again. */
    public function test_failures_outside_the_window_do_not_open_the_circuit(): void
    {
        $this->provider->will(ExtractorUnavailableException::because('503'));
        $circuit = $this->circuit();

        $this->assertOutage($circuit, null);
        $this->assertOutage($circuit, null);
        $this->clock->moveTo($this->clock->now()->modify('+61 seconds'));
        $this->assertOutage($circuit, null);
        $this->assertNull($this->store->state());
    }

    /** A success in between breaks the run of failures. */
    public function test_a_success_resets_the_failures(): void
    {
        $circuit = $this->circuit();
        $this->provider->will(ExtractorUnavailableException::because('503'));
        $this->assertOutage($circuit, null);
        $this->assertOutage($circuit, null);

        $this->provider->will(new ExtractedReservation(service: 'corte'));
        $this->assertSame('corte', $circuit->extract(self::PHRASE, $this->context())->service);

        $this->provider->will(ExtractorUnavailableException::because('503'));
        $this->assertOutage($circuit, null);
        $this->assertOutage($circuit, null);
        $this->assertNull($this->store->state());
    }

    /** After the open time ONE probe is let through; if it works the circuit closes and the calls go through again. */
    public function test_after_the_open_time_a_successful_probe_closes_the_circuit(): void
    {
        $circuit = $this->openCircuit();
        $this->clock->moveTo($this->clock->now()->modify('+60 seconds'));
        $this->provider->will(new ExtractedReservation(service: 'corte'));

        $this->assertSame('corte', $circuit->extract(self::PHRASE, $this->context())->service);
        $this->assertNull($this->store->state());
        $this->assertSame(4, $this->provider->calls);

        $circuit->extract(self::PHRASE, $this->context());
        $this->assertSame(5, $this->provider->calls);
        $this->assertTransitions(['closed>open', 'open>half_open', 'half_open>closed']);
        $this->assertSame(60, $this->logged('half_open', 'closed')->context['open_duration_seconds']);
    }

    /** A failed probe opens the circuit again for another full open time, and only the probe called the provider. */
    public function test_a_failed_probe_opens_the_circuit_again(): void
    {
        $circuit = $this->openCircuit();
        $this->clock->moveTo($this->clock->now()->modify('+60 seconds'));

        $this->assertOutage($circuit, 60);
        $this->assertSame(4, $this->provider->calls);
        $this->assertOutage($circuit, 60);
        $this->assertSame(4, $this->provider->calls, 'The reopened circuit must not call the provider.');
        $this->assertSame('probe_failed', $this->logged('half_open', 'open')->context['reason']);
    }

    /** While one process makes the probe call, the others are answered at once as unavailable. */
    public function test_only_one_probe_is_let_through(): void
    {
        $circuit = $this->openCircuit();
        $this->clock->moveTo($this->clock->now()->modify('+60 seconds'));
        $this->assertTrue($this->store->claimProbe());

        $this->assertOutage($circuit, 1);
        $this->assertSame(3, $this->provider->calls);
    }

    /** A 401 or a 403 opens the circuit at once and is logged as critical, whatever the count. */
    public function test_refused_credentials_open_the_circuit_at_once_and_are_logged_as_critical(): void
    {
        $this->provider->will(ExtractorUnavailableException::credentialsRefused(401));
        $circuit = $this->circuit();

        $this->assertOutage($circuit, 60);
        $this->assertOutage($circuit, 60);
        $this->assertSame(1, $this->provider->calls);
        $this->assertSame('critical', $this->logged('closed', 'open')->level);
        $this->assertSame('credentials_refused', $this->logged('closed', 'open')->context['reason']);
    }

    /** An invalid answer of the model is not a failure of availability, however many come; it is logged by the adapter. */
    public function test_an_invalid_answer_does_not_open_the_circuit(): void
    {
        $this->provider->will(ExtractionInvalidException::because('not JSON'));
        $circuit = $this->circuit();

        for ($i = 0; $i < 6; $i++) {
            try {
                $circuit->extract(self::PHRASE, $this->context());
                $this->fail('The invalid answer must be refused.');
            } catch (ExtractionInvalidException) {
            }
        }

        $this->assertSame(6, $this->provider->calls);
        $this->assertNull($this->store->state());
    }

    /** A provider that answers something invalid is reachable: the probe that gets it closes the circuit. */
    public function test_an_invalid_answer_to_the_probe_closes_the_circuit(): void
    {
        $circuit = $this->openCircuit();
        $this->clock->moveTo($this->clock->now()->modify('+60 seconds'));
        $this->provider->will(ExtractionInvalidException::because('not JSON'));

        try {
            $circuit->extract(self::PHRASE, $this->context());
            $this->fail('The invalid answer must reach the caller.');
        } catch (ExtractionInvalidException) {
            $this->assertNull($this->store->state());
        }
    }

    /** Our own misconfiguration and a missing key say nothing about the provider: they never count. */
    public function test_misconfiguration_and_a_missing_key_do_not_count(): void
    {
        $circuit = $this->circuit();

        foreach ([ExtractorMisconfiguredException::because('no model'), ExtractorUnavailableException::notConfigured()] as $failure) {
            $this->provider->will($failure);

            for ($i = 0; $i < 5; $i++) {
                try {
                    $circuit->extract(self::PHRASE, $this->context());
                    $this->fail('The failure must reach the caller.');
                } catch (ExtractorMisconfiguredException|ExtractorUnavailableException) {
                }
            }
        }

        $this->assertNull($this->store->state());
        $this->assertSame(10, $this->provider->calls);
    }

    /** A 429 that says how long to wait keeps the circuit open at least that long (and no more than one hour). */
    public function test_the_retry_after_of_the_provider_extends_the_open_time(): void
    {
        $circuit = $this->circuit();
        $this->provider->will(ExtractorUnavailableException::because('503'), ExtractorUnavailableException::because('503'), ExtractorUnavailableException::rateLimited(300));

        $this->assertOutage($circuit, null);
        $this->assertOutage($circuit, null);
        $this->assertOutage($circuit, 300);

        $this->provider->will(ExtractorUnavailableException::rateLimited(99_999));
        $this->clock->moveTo($this->clock->now()->modify('+300 seconds'));
        $this->assertOutage($circuit, 3600);
    }

    /** If the cache is down the circuit behaves as closed: the provider is called and nothing breaks. */
    public function test_a_cache_that_is_down_never_breaks_the_request(): void
    {
        $down = new AiCircuitStore(new class implements Factory
        {
            public function store($name = null): never
            {
                throw new RuntimeException('connection refused: secret-host');
            }
        }, 'redis', 'gemini');
        $circuit = $this->circuit($down);

        $this->provider->will(ExtractorUnavailableException::because('503'));
        for ($i = 0; $i < 5; $i++) {
            $this->assertOutage($circuit, null);
        }
        $this->assertSame(5, $this->provider->calls);

        $this->provider->will(new ExtractedReservation(service: 'corte'));
        $this->assertSame('corte', $circuit->extract(self::PHRASE, $this->context())->service);
        $this->assertStringNotContainsString('secret-host', $this->allLogs());
    }

    /** Every transition says why and for how long, and no log keeps the phrase. */
    public function test_the_transitions_are_logged_without_the_phrase(): void
    {
        $circuit = $this->openCircuit();
        $this->clock->moveTo($this->clock->now()->modify('+60 seconds'));
        $this->provider->will(new ExtractedReservation);
        $circuit->extract(self::PHRASE, $this->context());

        $opened = $this->logged('closed', 'open');
        $this->assertSame('consecutive_failures', $opened->context['reason']);
        $this->assertSame(60, $opened->context['open_seconds']);
        $this->assertSame(3, $opened->context['failures']);
        $this->assertSame(60, $this->logged('open', 'half_open')->context['waited_seconds']);
        $this->assertStringNotContainsString(self::PHRASE, $this->allLogs());
    }

    /** The circuit failure carries what the use case needs to answer with Retry-After. */
    public function test_the_open_circuit_failure_says_its_cause_and_the_seconds_left(): void
    {
        $circuit = $this->openCircuit();

        try {
            $circuit->extract(self::PHRASE, $this->context());
            $this->fail('An open circuit must refuse the call.');
        } catch (ExtractorUnavailableException $exception) {
            $this->assertSame(ExtractorFailure::CircuitOpen, $exception->failure);
            $this->assertSame(60, $exception->retryAfterSeconds);
        }
    }

    /** The circuit under test, over the scripted provider and the given store. */
    private function circuit(?AiCircuitStore $store = null): CircuitBreakerReservationExtractor
    {
        return new CircuitBreakerReservationExtractor($this->provider, $store ?? $this->store, $this->clock, 'gemini', 3, 60, 60);
    }

    /** A circuit that three failures just opened, at the current instant of the clock. */
    private function openCircuit(): CircuitBreakerReservationExtractor
    {
        $this->provider->will(ExtractorUnavailableException::because('503'));
        $circuit = $this->circuit();

        for ($i = 0; $i < 3; $i++) {
            try {
                $circuit->extract(self::PHRASE, $this->context());
            } catch (ExtractorUnavailableException) {
            }
        }

        return $circuit;
    }

    /** The call fails as unavailable, with the seconds the circuit says are left (null when there is no circuit yet). */
    private function assertOutage(CircuitBreakerReservationExtractor $circuit, ?int $retryAfter): void
    {
        try {
            $circuit->extract(self::PHRASE, $this->context());
            $this->fail('The call must fail as unavailable.');
        } catch (ExtractorUnavailableException $exception) {
            $this->assertSame($retryAfter, $exception->retryAfterSeconds);
        }
    }

    private function context(): ExtractionContext
    {
        return new ExtractionContext(['Corte'], ['Kevin'], $this->clock->now());
    }

    /** The log of the transition between two states. */
    private function logged(string $from, string $to): MessageLogged
    {
        foreach ($this->logs as $log) {
            if (($log->context['from'] ?? null) === $from && ($log->context['to'] ?? null) === $to) {
                return $log;
            }
        }

        $this->fail("The transition {$from} to {$to} was not logged.");
    }

    /**
     * The transitions logged, in order, as "from>to".
     *
     * @param  list<string>  $expected
     */
    private function assertTransitions(array $expected): void
    {
        $transitions = [];

        foreach ($this->logs as $log) {
            if (isset($log->context['from'], $log->context['to'])) {
                $transitions[] = $log->context['from'].'>'.$log->context['to'];
            }
        }

        $this->assertSame($expected, $transitions);
    }

    private function allLogs(): string
    {
        return (string) json_encode(array_map(fn (MessageLogged $log): array => [$log->message, $log->context], $this->logs));
    }
}
