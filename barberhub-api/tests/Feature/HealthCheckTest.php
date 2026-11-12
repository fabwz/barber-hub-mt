<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Reservations\Reservations\Infrastructure\Ai\AiCircuitStore;
use Src\Shared\Domain\AiStatus;
use Src\Shared\Domain\Contracts\AiAvailability;
use Src\Shared\Domain\Contracts\DatabaseHealth;
use Src\Shared\Domain\Contracts\QueueHealth;
use Tests\TestCase;

final class HealthCheckTest extends TestCase
{
    /**
     * The health route answers 200 or 503 without authentication or internal details (RNF-14).
     *
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('scenarios')]
    public function test_health_route_reports_the_state_of_the_dependencies(
        bool $databaseWorks,
        bool $queueWorks,
        int $status,
        array $body,
    ): void {
        $this->app->instance(DatabaseHealth::class, $this->databaseHealth($databaseWorks));
        $this->app->instance(QueueHealth::class, $this->queueHealth($queueWorks));

        $this->getJson('/salud')
            ->assertStatus($status)
            ->assertExactJson($body);
    }

    /**
     * Dependencies are simulated with fakes, so the real database is never touched.
     *
     * @return iterable<string, array{bool, bool, int, array<string, string>}>
     */
    public static function scenarios(): iterable
    {
        yield 'nothing fails' => [true, true, 200, ['estado' => 'ok', 'ia' => 'degradada']];
        yield 'only the database fails' => [false, true, 503, ['estado' => 'fallo', 'ia' => 'degradada']];
        yield 'only the queue fails' => [true, false, 503, ['estado' => 'fallo', 'ia' => 'degradada']];
    }

    /** Without key or model the public route says degraded, never that the configuration is missing, and the body names no variable. */
    public function test_ai_without_configuration_is_shown_as_degraded(): void
    {
        $this->workingDependencies();

        foreach ([['', ''], ['test-key', '']] as [$key, $model]) {
            config(['ai.gemini.api_key' => $key, 'ai.gemini.model' => $model]);
            $content = $this->getJson('/salud')->assertOk()->assertExactJson(['estado' => 'ok', 'ia' => 'degradada'])->getContent();

            $this->assertStringNotContainsString('configurada', (string) $content);
            $this->assertStringNotContainsString('GEMINI', (string) $content);
        }
    }

    /** The internal state still tells "not configured" apart, for the log and the tools. */
    public function test_the_port_still_distinguishes_a_missing_configuration(): void
    {
        config(['ai.gemini.api_key' => '', 'ai.gemini.model' => '']);

        $this->assertSame(AiStatus::NotConfigured, $this->app->make(AiAvailability::class)->status());
    }

    /** The warning about the missing configuration is logged once, not on every health check, and has no name or value of a key. */
    public function test_the_missing_configuration_is_warned_once(): void
    {
        $this->workingDependencies();
        config(['ai.gemini.api_key' => '', 'ai.gemini.model' => '']);
        $warnings = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$warnings): void {
            if ($log->level === 'warning') {
                $warnings[] = $log->message.json_encode($log->context);
            }
        });

        foreach (range(1, 4) as $ignored) {
            $this->getJson('/salud')->assertOk();
        }

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('AI configuration is missing', $warnings[0]);
        $this->assertStringNotContainsStringIgnoringCase('key', $warnings[0]);
        $this->assertStringNotContainsString('GEMINI', $warnings[0]);

        // The router keeps the controller it built, so the changes of configuration go through the port, which is built each time.
        config(['ai.gemini.api_key' => 'test-key', 'ai.gemini.model' => 'test-model']);
        $this->assertSame(AiStatus::Available, $this->app->make(AiAvailability::class)->status());
        config(['ai.gemini.api_key' => '']);
        $this->assertSame(AiStatus::NotConfigured, $this->app->make(AiAvailability::class)->status());

        $this->assertCount(2, $warnings, 'A new change of state warns again.');
    }

    /** A configured provider with a closed circuit is available. */
    public function test_ai_is_available_when_it_is_configured(): void
    {
        $this->workingDependencies();
        config(['ai.gemini.api_key' => 'test-key', 'ai.gemini.model' => 'test-model']);

        $this->getJson('/salud')->assertOk()->assertExactJson(['estado' => 'ok', 'ia' => 'disponible']);
    }

    /** An open circuit makes the provider degraded, and the health route still answers 200: nothing else depends on it (RNF-11). */
    public function test_ai_is_degraded_while_the_circuit_is_open_and_the_general_state_stays_ok(): void
    {
        $this->workingDependencies();
        config(['ai.gemini.api_key' => 'test-key', 'ai.gemini.model' => 'test-model']);
        $this->app->make(AiCircuitStore::class)->open(time(), time() + 60, 'consecutive_failures');

        $this->getJson('/salud')->assertOk()->assertExactJson(['estado' => 'ok', 'ia' => 'degradada']);
    }

    /** If the cache of the circuit is down the route still answers 200 and reports the provider as available. */
    public function test_a_cache_that_is_down_does_not_break_the_health_route(): void
    {
        $this->workingDependencies();
        config(['ai.gemini.api_key' => 'test-key', 'ai.gemini.model' => 'test-model', 'ai.circuit.store' => 'not-a-store']);

        $this->getJson('/salud')->assertOk()->assertExactJson(['estado' => 'ok', 'ia' => 'disponible']);
    }

    /** The database and the queue work, so only the language provider can change the answer. */
    private function workingDependencies(): void
    {
        $this->app->instance(DatabaseHealth::class, $this->databaseHealth(true));
        $this->app->instance(QueueHealth::class, $this->queueHealth(true));
    }

    private function databaseHealth(bool $available): DatabaseHealth
    {
        return new readonly class($available) implements DatabaseHealth
        {
            public function __construct(private bool $available) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }
        };
    }

    private function queueHealth(bool $available): QueueHealth
    {
        return new readonly class($available) implements QueueHealth
        {
            public function __construct(private bool $available) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }
        };
    }
}
