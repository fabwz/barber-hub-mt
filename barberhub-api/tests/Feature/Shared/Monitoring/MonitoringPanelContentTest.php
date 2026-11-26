<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\Monitoring;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Monitoring\UsesMonitoringPanel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** What the panel shows (RNF-15, RNF-09, RNF-04): errors, times, queues, failed tasks and the health route, each with its empty state. */
final class MonitoringPanelContentTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use UsesMonitoringPanel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMonitoring();
    }

    /** With nothing recorded every section says so, the two queues still show, and the page is semantic HTML without JavaScript. */
    public function test_every_section_has_its_empty_state(): void
    {
        $response = $this->openPanel()->assertOk();

        $response
            ->assertSee('No hay errores registrados en este período.')
            ->assertSee('Todavía no hay peticiones registradas en este período.')
            ->assertSee('No hay tareas fallidas.')
            ->assertSee('Todavía no se ha consultado la ruta de salud.')
            ->assertSee('Sin consultas en la ventana.')
            ->assertSee('No hubo fallos en la ventana.');

        $html = (string) $response->getContent();

        $this->assertMatchesRegularExpression('/<th scope="row" class="mono">default<\/th>\s*<td class="num">0<\/td>\s*<td class="num">0<\/td>\s*<td class="num">0<\/td>\s*<td class="num">0<\/td>\s*<td>Al día<\/td>/', $html);
        $this->assertMatchesRegularExpression('/<th scope="row" class="mono">realtime<\/th>/', $html);
        $this->assertStringContainsString('<html lang="es-CR">', $html);
        $this->assertStringContainsString('<caption>', $html);
        $this->assertStringContainsString('scope="col"', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    /** The errors of the window by class of exception and route; what is older than the window, or has no exception, is not an error. */
    public function test_errors_are_grouped_by_exception_class_and_route(): void
    {
        $this->stats('2030-03-01 07:50:00-06', 'POST', 'api/v1/barberias/{barberia}/reservas', 5, 'RuntimeException', requests: 3);
        $this->stats('2030-03-01 07:51:00-06', 'POST', 'api/v1/barberias/{barberia}/reservas', 5, 'RuntimeException', requests: 2);
        $this->stats('2030-03-01 07:52:00-06', 'GET', 'api/v1/cuenta', 4, 'Illuminate\Auth\AuthenticationException', requests: 1);
        $this->stats('2030-03-01 07:53:00-06', 'GET', 'api/v1/cuenta', 2, '', requests: 40);
        $this->stats('2030-02-27 07:50:00-06', 'GET', 'api/v1/viejo', 5, 'LogicException', requests: 9);

        $html = (string) $this->openPanel()->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<td class="mono">RuntimeException<\/td>\s*<td>POST<\/td>\s*<td class="mono">api\/v1\/barberias\/\{barberia\}\/reservas<\/td>\s*<td>5 xx<\/td>\s*<td class="num">5<\/td>/', $html);
        $this->assertStringContainsString('Illuminate\Auth\AuthenticationException', $html);
        $this->assertStringNotContainsString('LogicException', $html);
        $this->assertStringNotContainsString('No hay errores registrados', $html);
    }

    /** The times by route: requests, average, maximum and the 5xx, the slowest on average first. */
    public function test_times_are_shown_by_route(): void
    {
        $this->stats('2030-03-01 07:50:00-06', 'GET', 'api/v1/rapida', 2, '', requests: 10, totalMs: 100, maxMs: 30);
        $this->stats('2030-03-01 07:51:00-06', 'GET', 'api/v1/lenta', 2, '', requests: 2, totalMs: 1000, maxMs: 700);
        $this->stats('2030-03-01 07:51:00-06', 'GET', 'api/v1/lenta', 5, 'RuntimeException', requests: 1, totalMs: 500, maxMs: 500);

        $html = (string) $this->openPanel()->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/api\/v1\/lenta<\/td>\s*<td class="num">3<\/td>\s*<td class="num">500<\/td>\s*<td class="num">700<\/td>\s*<td class="num">1<\/td>/', $html);
        $this->assertMatchesRegularExpression('/api\/v1\/rapida<\/td>\s*<td class="num">10<\/td>\s*<td class="num">10<\/td>\s*<td class="num">30<\/td>\s*<td class="num">0<\/td>/', $html);
        $this->assertLessThan(strpos($html, 'api/v1/rapida</td>'), strpos($html, 'api/v1/lenta</td>'));
    }

    /** The queues apart: ready, scheduled and held tasks; a ready task of `default` older than 60 s raises the alert with text, not only with color. */
    public function test_a_late_default_queue_raises_the_alert_and_realtime_does_not(): void
    {
        $now = $this->monitoringClock->now()->getTimestamp();
        $this->job('default', availableAt: $now - 120);
        $this->job('default', availableAt: $now - 10);
        $this->job('default', availableAt: $now + 3600);
        $this->job('default', availableAt: $now - 500, reservedAt: $now - 400);
        $this->job('realtime', availableAt: $now - 900);

        $response = $this->openPanel()->assertOk()->assertSee('Atrasada: default (120 s)', false)->assertDontSee('Atrasada: realtime', false);
        $html = (string) $response->getContent();

        $this->assertMatchesRegularExpression('/<th scope="row" class="mono">default<\/th>\s*<td class="num">2<\/td>\s*<td class="num">1<\/td>\s*<td class="num">1<\/td>\s*<td class="num">120<\/td>\s*<td>Atrasada<\/td>/', $html);
        $this->assertMatchesRegularExpression('/<th scope="row" class="mono">realtime<\/th>\s*<td class="num">1<\/td>\s*<td class="num">0<\/td>\s*<td class="num">0<\/td>\s*<td class="num">900<\/td>\s*<td>Al día<\/td>/', $html);
    }

    /** A queue exactly at the threshold is still on time. */
    public function test_sixty_seconds_is_still_on_time(): void
    {
        $this->job('default', availableAt: $this->monitoringClock->now()->getTimestamp() - 60);

        $this->openPanel()->assertOk()->assertDontSee('Atrasada');
    }

    /** The failed tasks by job and queue, with the class of the exception and the identifier, and the recent ones; never the payload or the message. */
    public function test_failed_tasks_are_grouped_and_listed_without_payload_or_message(): void
    {
        $first = $this->failedJob('default', 'Src\Notifications\Notices\Infrastructure\Jobs\SendNoticeJob', 'RuntimeException: el correo fallo en /var/www/app.php:10', '2030-03-01 07:00:00');
        $this->failedJob('default', 'Src\Notifications\Notices\Infrastructure\Jobs\SendNoticeJob', 'Symfony\Component\Mailer\Exception\TransportException: 429', '2030-03-01 07:30:00');
        $this->failedJob('realtime', 'Illuminate\Broadcasting\BroadcastEvent', 'RuntimeException: sin conexion', '2030-03-01 07:45:00');

        $html = (string) $this->openPanel()->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/SendNoticeJob<\/td>\s*<td class="mono">default<\/td>\s*<td class="num">2<\/td>\s*<td>01\/03\/2030 07:30:00<\/td>\s*<td class="mono">Symfony\\\\Component\\\\Mailer\\\\Exception\\\\TransportException<\/td>/', $html);
        $this->assertMatchesRegularExpression('/BroadcastEvent<\/td>\s*<td class="mono">realtime<\/td>\s*<td class="num">1<\/td>/', $html);
        $this->assertStringContainsString($first, $html);
        $this->assertStringNotContainsString('el correo fallo', $html);
        $this->assertStringNotContainsString('/var/www', $html);
        $this->assertStringNotContainsString('"displayName"', $html);
        $this->assertStringNotContainsString('No hay tareas fallidas.', $html);
    }

    /** A payload that is not JSON does not break the panel. */
    public function test_a_failed_task_with_an_unreadable_payload_is_still_listed(): void
    {
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => 'esto no es json', 'exception' => 'LogicException: x', 'failed_at' => '2030-03-01 07:00:00']);

        $this->openPanel()->assertOk()->assertSee('(sin nombre)')->assertSee('LogicException');
    }

    /** The health route: the latest answer, the recent failures with the dependency that failed, and the availability of the window. */
    public function test_a_failure_of_the_health_route_is_visible(): void
    {
        $this->health('2030-03-01 06:00:00-06', databaseOk: false, queueOk: true, checks: 2);
        $this->health('2030-03-01 07:00:00-06', databaseOk: true, queueOk: true, checks: 98);
        $this->health('2030-03-01 07:58:00-06', databaseOk: true, queueOk: false, checks: 1);

        $response = $this->openPanel()->assertOk();
        $html = (string) $response->getContent();

        $response->assertSee('Falló (503)')->assertDontSee('Sin chequeos recientes')->assertSee('97,03 % de 101 consultas');
        $this->assertMatchesRegularExpression('/01\/03\/2030 07:58:00<\/td>\s*<td>Funciona<\/td>\s*<td>Falló<\/td>\s*<td class="num">1<\/td>/', $html);
        $this->assertMatchesRegularExpression('/01\/03\/2030 06:00:00<\/td>\s*<td>Falló<\/td>\s*<td>Funciona<\/td>\s*<td class="num">2<\/td>/', $html);
    }

    /** The latest answer was a 200: the panel says so. */
    public function test_a_working_health_route_is_shown_as_correct(): void
    {
        $this->health('2030-03-01 07:59:00-06', databaseOk: true, queueOk: true, checks: 4);

        $this->openPanel()->assertOk()->assertSee('Correcta (200)')->assertSee('100,00 % de 4 consultas')->assertDontSee('Sin chequeos recientes');
    }

    /** When nobody has asked for longer than the threshold, the panel warns, even if the last answer was fine. */
    public function test_a_health_route_nobody_asks_is_flagged(): void
    {
        $this->health('2030-03-01 07:48:00-06', databaseOk: true, queueOk: true, checks: 4);

        $this->openPanel()->assertOk()->assertSee('Sin chequeos recientes')->assertSee('Correcta (200)');
    }

    /** A failure older than the window of availability is not shown. */
    public function test_failures_older_than_the_window_are_not_shown(): void
    {
        $this->health('2030-02-01 06:00:00-06', databaseOk: false, queueOk: false, checks: 7);
        $this->health('2030-03-01 07:59:00-06', databaseOk: true, queueOk: true, checks: 1);

        $this->openPanel()->assertOk()->assertSee('No hubo fallos en la ventana.')->assertSee('100,00 % de 1 consultas');
    }

    private function stats(string $minute, string $method, string $route, int $statusClass, string $exception, int $requests, ?int $totalMs = null, int $maxMs = 10): void
    {
        DB::table('http_stats')->insert([
            'bucket_minute' => $minute,
            'method' => $method,
            'route_pattern' => $route,
            'status_class' => $statusClass,
            'exception_class' => $exception,
            'requests' => $requests,
            'total_ms' => $totalMs ?? $requests * 10,
            'max_ms' => $maxMs,
        ]);
    }

    private function job(string $queue, int $availableAt, ?int $reservedAt = null): void
    {
        DB::table('jobs')->insert(['queue' => $queue, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => $reservedAt, 'available_at' => $availableAt, 'created_at' => $availableAt]);
    }

    private function failedJob(string $queue, string $job, string $exception, string $failedAt): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => $queue,
            'payload' => (string) json_encode(['displayName' => $job, 'job' => 'Illuminate\Queue\CallQueuedHandler@call', 'data' => ['command' => 'serialized-con-datos']]),
            'exception' => $exception,
            'failed_at' => $failedAt,
        ]);

        return $uuid;
    }

    private function health(string $minute, bool $databaseOk, bool $queueOk, int $checks): void
    {
        DB::table('health_checks')->insert([
            'bucket_minute' => $minute,
            'database_ok' => $databaseOk,
            'queue_ok' => $queueOk,
            'healthy' => $databaseOk && $queueOk,
            'ai_status' => 'degradada',
            'checks' => $checks,
            'last_checked_at' => $minute,
        ]);
    }
}
