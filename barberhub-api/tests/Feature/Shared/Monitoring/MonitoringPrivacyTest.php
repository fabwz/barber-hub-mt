<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\Monitoring;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Src\Shared\Domain\Contracts\DatabaseHealth;
use Src\Shared\Domain\Contracts\QueueHealth;
use Tests\Support\Monitoring\FixedHealth;
use Tests\Support\Monitoring\UsesMonitoringPanel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** The monitoring keeps no personal data and no secret, in the tables or on the page (RNF-24, SE-03): the seeded case puts them everywhere they could leak from. */
final class MonitoringPrivacyTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use UsesMonitoringPanel;

    private const string EMAIL = 'ana.mora@barberhub.test';

    private const string PHONE = '2460-1122';

    private const string TOKEN = '7|tokenSecretoDeSanctum1234567890';

    private const string BODY_SECRET = 'cuerpo-secreto-con-tarjeta-4242';

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMonitoring();
    }

    /** Requests that carry contact data and a token in the URL, the query, the body and the headers leave none of it in the tables or on the page. */
    public function test_no_personal_data_or_secret_reaches_the_tables_or_the_page(): void
    {
        $this->app->instance(DatabaseHealth::class, new FixedHealth(true));
        $this->app->instance(QueueHealth::class, new FixedHealth(false));

        $this->withToken(self::TOKEN)->withHeaders(['X-Contacto' => self::EMAIL])
            ->postJson('/api/v1/barberias/'.Str::uuid7().'/reservas?correo='.self::EMAIL.'&telefono='.self::PHONE, ['correo' => self::EMAIL, 'telefono' => self::PHONE, 'nota' => self::BODY_SECRET]);
        $this->getJson('/api/v1/enlaces/'.self::EMAIL);
        $this->getJson('/api/v1/'.self::PHONE.'/'.self::EMAIL);
        $this->getJson('/salud');

        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => (string) json_encode(['displayName' => 'Src\Notifications\Notices\Infrastructure\Jobs\SendNoticeJob', 'data' => ['destinatario' => self::EMAIL, 'telefono' => self::PHONE]]),
            'exception' => 'RuntimeException: no se pudo avisar a '.self::EMAIL.' ('.self::PHONE.') con host=db.interno password=hunter2 in /var/www/app/Jobs/X.php:12',
            'failed_at' => '2030-03-01 07:30:00',
        ]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => (string) json_encode(['correo' => self::EMAIL]), 'attempts' => 0, 'reserved_at' => null, 'available_at' => 1, 'created_at' => 1]);

        $stored = (string) json_encode([DB::table('http_stats')->get(), DB::table('health_checks')->get()]);
        $page = (string) $this->openPanel()->assertOk()->getContent();

        $this->assertStringContainsString('SendNoticeJob', $page);
        $this->assertStringContainsString($uuid, $page);
        $this->assertStringContainsString('RuntimeException', $page);
        $this->assertNotSame('[[],[]]', $stored);

        foreach ([self::EMAIL, 'ana.mora', self::PHONE, '2460', self::TOKEN, 'tokenSecreto', self::BODY_SECRET, 'tarjeta', 'hunter2', 'db.interno', '/var/www', 'password', 'destinatario', 'Bearer', self::PANEL_PASSWORD] as $secret) {
            $this->assertStringNotContainsString($secret, $stored, "The tables hold «{$secret}».");
            $this->assertStringNotContainsString($secret, $page, "The page shows «{$secret}».");
        }
    }

    /** The tables have exactly the closed list of columns: a new column that could hold personal data fails here until someone decides it. */
    public function test_the_tables_hold_only_the_closed_list_of_columns(): void
    {
        $this->assertSame(
            ['bucket_minute', 'method', 'route_pattern', 'status_class', 'exception_class', 'requests', 'total_ms', 'max_ms'],
            Schema::getColumnListing('http_stats'),
        );
        $this->assertSame(
            ['bucket_minute', 'database_ok', 'queue_ok', 'healthy', 'ai_status', 'checks', 'last_checked_at'],
            Schema::getColumnListing('health_checks'),
        );
    }

    /** The data the use cases hand to the page have no field for a payload, a message, a body, contact data, a token or a person. */
    public function test_the_dtos_have_no_field_for_personal_data_or_for_what_hides_it(): void
    {
        $forbidden = ['payload', 'exception', 'message', 'trace', 'body', 'email', 'correo', 'phone', 'telefono', 'token', 'user', 'usuario', 'sql', 'query', 'url', 'ip'];
        $files = glob(base_path('src/Shared/Monitoring/Application/DTOs/*.php'));

        $this->assertNotEmpty($files);

        foreach ((array) $files as $file) {
            $class = 'Src\\Shared\\Monitoring\\Application\\DTOs\\'.basename((string) $file, '.php');

            if (! class_exists($class)) {
                $this->fail("{$class} does not exist.");
            }

            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                $this->assertNotContains(strtolower($property->getName()), $forbidden, "{$class}::\${$property->getName()}");
            }
        }
    }
}
