<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\Monitoring;

use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Src\Shared\Domain\Contracts\DatabaseHealth;
use Src\Shared\Domain\Contracts\QueueHealth;
use Src\Shared\Monitoring\Domain\Contracts\HealthChecksWriter;
use Src\Shared\Monitoring\Domain\Contracts\RequestStatsWriter;
use Src\Shared\Monitoring\Infrastructure\Persistence\DatabaseMonitoringRetention;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Monitoring\FailingHealthChecksWriter;
use Tests\Support\Monitoring\FailingRequestStatsWriter;
use Tests\Support\Monitoring\FixedHealth;
use Tests\Support\Monitoring\UsesMonitoringPanel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\CapturesLogRecords;
use Tests\TestCase;

/**
 * What the monitoring writes while the application answers (RNF-15, ADR 0013): one row per minute, route pattern, class of status and class of
 * exception; one row per minute and result of the health route; nothing when it is off; and never a changed answer when writing fails.
 */
final class MonitoringRecordingTest extends TestCase
{
    use CapturesLogRecords;
    use RefreshDatabaseAsOwner;
    use UsesMonitoringPanel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMonitoring();
    }

    /** Requests of the same minute, route and status add up in one row; another minute is another row. */
    public function test_requests_are_added_up_by_minute_route_and_status(): void
    {
        $this->getJson('/api/v1')->assertOk();
        $this->getJson('/api/v1')->assertOk();
        $this->monitoringClock->moveTo(new DateTimeImmutable('2030-03-01 08:01:10', $this->monitoringClock->now()->getTimezone()));
        $this->getJson('/api/v1')->assertOk();

        $rows = DB::table('http_stats')->orderBy('bucket_minute')->get()->all();

        $this->assertCount(2, $rows);
        [$first, $second] = $rows;
        $this->assertSame(['GET', 'api/v1', 2, '', 2], [$first->method, $first->route_pattern, $first->status_class, $first->exception_class, $first->requests]);
        $this->assertSame('2030-03-01 08:00:00', (new DateTimeImmutable($first->bucket_minute))->format('Y-m-d H:i:s'));
        $this->assertSame(1, $second->requests);
        $this->assertSame('2030-03-01 08:01:00', (new DateTimeImmutable($second->bucket_minute))->format('Y-m-d H:i:s'));
        $this->assertGreaterThanOrEqual($first->max_ms, $first->total_ms);
    }

    /** A URL that matches no route is grouped as «(sin ruta)»: the URL, with whatever it carries, is not stored, and the class of the exception is. */
    public function test_an_unknown_url_is_recorded_without_the_url(): void
    {
        $this->getJson('/api/v1/cuentas-de-ana.mora@barberhub.test?telefono=2460-1122')->assertNotFound();

        $row = DB::table('http_stats')->first();

        $this->assertNotNull($row);
        $this->assertSame(['(sin ruta)', 4, NotFoundHttpException::class], [$row->route_pattern, $row->status_class, $row->exception_class]);
        $this->assertStringNotContainsString('ana.mora', (string) json_encode(DB::table('http_stats')->get()));
        $this->assertStringNotContainsString('2460', (string) json_encode(DB::table('http_stats')->get()));
    }

    /** A request that ends in an unhandled exception is a 5xx with the CLASS of the exception, never its message; the answer is still the problem document. */
    public function test_an_unhandled_exception_is_recorded_with_its_class_and_never_its_message(): void
    {
        Route::get('api/v1/prueba-falla/{valor}', fn (string $valor) => throw new RuntimeException('no se pudo avisar a ana.mora@barberhub.test'));

        $this->getJson('/api/v1/prueba-falla/2460-1122')->assertStatus(500)->assertJsonPath('status', 500);

        $row = DB::table('http_stats')->first();

        $this->assertNotNull($row);
        $this->assertSame(['api/v1/prueba-falla/{valor}', 5, RuntimeException::class], [$row->route_pattern, $row->status_class, $row->exception_class]);
        $stored = (string) json_encode(DB::table('http_stats')->get());
        $this->assertStringNotContainsString('ana.mora', $stored);
        $this->assertStringNotContainsString('2460', $stored);
    }

    /** With the switch off nothing is written, not even by the health route. */
    public function test_nothing_is_written_with_the_switch_off(): void
    {
        config(['monitoring.enabled' => false]);
        $this->app->instance(DatabaseHealth::class, new FixedHealth(true));
        $this->app->instance(QueueHealth::class, new FixedHealth(true));

        $this->getJson('/api/v1')->assertOk();
        $this->getJson('/salud')->assertOk();
        $this->getJson('/monitoreo')->assertNotFound();

        $this->assertSame(0, DB::table('http_stats')->count());
        $this->assertSame(0, DB::table('health_checks')->count());
        $this->assertFalse(Cache::has(DatabaseMonitoringRetention::LOCK));
    }

    /** Asking the health route many times in a minute leaves one row per result, and the answer is the same as without the monitoring. */
    public function test_the_health_route_leaves_one_row_per_minute_and_result(): void
    {
        $database = new FixedHealth(true);
        $queue = new FixedHealth(true);
        $this->app->instance(DatabaseHealth::class, $database);
        $this->app->instance(QueueHealth::class, $queue);

        foreach (range(1, 5) as $ignored) {
            $this->getJson('/salud')->assertOk()->assertExactJson(['estado' => 'ok', 'ia' => 'degradada']);
        }

        $database->available = false;
        $this->getJson('/salud')->assertStatus(503)->assertExactJson(['estado' => 'fallo', 'ia' => 'degradada']);
        $this->getJson('/salud')->assertStatus(503);
        $database->available = true;
        $queue->available = false;
        $this->getJson('/salud')->assertStatus(503);

        $rows = DB::table('health_checks')->orderBy('database_ok')->orderBy('queue_ok')->get();

        $this->assertCount(3, $rows);
        $this->assertSame(
            [[false, true, false, 2], [true, false, false, 1], [true, true, true, 5]],
            $rows->map(fn (object $row): array => [$row->database_ok, $row->queue_ok, $row->healthy, $row->checks])->all(),
        );
        $this->assertSame('degradada', DB::table('health_checks')->value('ai_status'));
    }

    /** A failure of the record, with a message that carries a secret, changes no answer, and only its class is logged. */
    public function test_a_failure_to_record_changes_no_answer_and_logs_only_its_class(): void
    {
        $this->captureLogRecords();
        $this->app->instance(RequestStatsWriter::class, new FailingRequestStatsWriter);
        $this->app->instance(HealthChecksWriter::class, new FailingHealthChecksWriter);
        $this->app->instance(DatabaseHealth::class, new FixedHealth(true));
        $this->app->instance(QueueHealth::class, new FixedHealth(true));

        $this->getJson('/api/v1')->assertOk();
        $this->getJson('/salud')->assertOk()->assertExactJson(['estado' => 'ok', 'ia' => 'degradada']);

        $this->assertNotSame([], $this->recordsOf('Monitoring: the request was not recorded.'));
        $this->assertNotSame([], $this->recordsOf('Monitoring: the health check was not recorded.'));
        $this->assertSame(RuntimeException::class, $this->recordsOf('Monitoring: the health check was not recorded.')[0]->context['exception']);
        $this->assertStringNotContainsString('hunter2', $this->allLoggedText());
        $this->assertStringNotContainsString('db.interno', $this->allLoggedText());
    }

    /** What is older than 14 days is deleted by the next request, at most once an hour, and what is newer stays. */
    public function test_old_rows_are_purged_once_an_hour(): void
    {
        $this->insertStatsRow('2030-02-01 08:00:00-06', 'viejo');
        $this->insertStatsRow('2030-02-20 08:00:00-06', 'reciente');
        $this->insertHealthRow('2030-02-01 08:00:00-06');
        $this->insertHealthRow('2030-02-20 08:00:00-06');

        $this->getJson('/api/v1')->assertOk();

        $this->assertTrue(Cache::has(DatabaseMonitoringRetention::LOCK));
        $this->assertSame(0, DB::table('http_stats')->where('route_pattern', 'viejo')->count());
        $this->assertSame(1, DB::table('http_stats')->where('route_pattern', 'reciente')->count());
        $this->assertSame(1, DB::table('health_checks')->count());

        // Within the same hour the lock holds: a new old row is left for the next purge.
        $this->insertStatsRow('2030-02-02 08:00:00-06', 'viejo-2');
        $this->getJson('/api/v1')->assertOk();
        $this->assertSame(1, DB::table('http_stats')->where('route_pattern', 'viejo-2')->count());

        // An hour later the lock has expired and the next request purges it.
        $this->travel(3601)->seconds();
        $this->getJson('/api/v1')->assertOk();
        $this->assertSame(0, DB::table('http_stats')->where('route_pattern', 'viejo-2')->count());
    }

    private function insertStatsRow(string $minute, string $route): void
    {
        DB::table('http_stats')->insert(['bucket_minute' => $minute, 'method' => 'GET', 'route_pattern' => $route, 'status_class' => 2, 'exception_class' => '', 'requests' => 1, 'total_ms' => 5, 'max_ms' => 5]);
    }

    private function insertHealthRow(string $minute): void
    {
        DB::table('health_checks')->insert(['bucket_minute' => $minute, 'database_ok' => true, 'queue_ok' => true, 'healthy' => true, 'ai_status' => 'degradada', 'checks' => 1, 'last_checked_at' => $minute]);
    }
}
