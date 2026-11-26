<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\Monitoring;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Monitoring\UsesMonitoringPanel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Tenancy\BuildsAccessScenario;
use Tests\TestCase;

/**
 * Who opens the monitoring panel (RNF-15, ADR 0013): only whoever knows its credentials. It is a route of the platform, so a role in a
 * barbershop opens nothing, and it fails closed: with no secret configured it does not exist.
 */
final class MonitoringPanelAccessTest extends TestCase
{
    use BuildsAccessScenario;
    use RefreshDatabaseAsOwner;
    use UsesMonitoringPanel;

    /** The right user and password open it, and the page is never cached or indexed. */
    public function test_the_right_credentials_open_the_panel(): void
    {
        $this->enableMonitoring();

        $this->openPanel()
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Monitoreo de BarberHub');
    }

    /** Without credentials the browser is asked for them with a 401 and an empty body. */
    public function test_without_credentials_the_panel_asks_for_them(): void
    {
        $this->enableMonitoring();

        $this->get('/monitoreo')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Basic realm="BarberHub", charset="UTF-8"')
            ->assertContent('');
    }

    /** @return array<string, array{string, string}> */
    public static function wrongCredentials(): array
    {
        return [
            'wrong password' => ['operador-de-prueba', 'otra-clave'],
            'wrong user' => ['otro-operador', 'clave-solo-para-pruebas-9731'],
            'both wrong' => ['otro-operador', 'otra-clave'],
            'empty' => ['', ''],
            'the user as password' => ['operador-de-prueba', 'operador-de-prueba'],
        ];
    }

    #[DataProvider('wrongCredentials')]
    public function test_wrong_credentials_are_refused(string $user, string $password): void
    {
        $this->enableMonitoring();

        $this->openPanel($user, $password)->assertUnauthorized()->assertContent('');
    }

    /** A person with a role in a barbershop, or a session of Sanctum, opens nothing: the panel does not know roles (UP-06). */
    public function test_a_role_in_a_barbershop_does_not_open_the_panel(): void
    {
        $this->enableMonitoring();
        $this->buildAccessScenario();

        foreach (['anonymous', 'customer', 'other_customer', 'collaborator', 'assigned_collaborator', 'owner', 'foreign_owner', 'foreign_collaborator'] as $actor) {
            $this->sendAs($actor, 'GET', '/monitoreo')->assertUnauthorized();
        }
    }

    /** After the allowed failed attempts, even the right password is refused with 429 and a wait, and the lockout ends with time. */
    public function test_repeated_failures_lock_the_address_out(): void
    {
        $this->enableMonitoring();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->openPanel(password: 'adivinando-'.$attempt)->assertUnauthorized();
        }

        $locked = $this->openPanel();
        $locked->assertStatus(429);
        $this->assertGreaterThan(0, (int) $locked->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(300, (int) $locked->headers->get('Retry-After'));

        $this->travel(301)->seconds();
        $this->openPanel()->assertOk();
    }

    /** The first request of a browser has no credentials by design and does not count as a failed attempt; a success clears the count. */
    public function test_asking_for_credentials_and_a_success_do_not_count_against_the_address(): void
    {
        $this->enableMonitoring();

        for ($asked = 1; $asked <= 10; $asked++) {
            $this->get('/monitoreo')->assertUnauthorized();
        }

        for ($round = 1; $round <= 3; $round++) {
            for ($attempt = 1; $attempt <= 4; $attempt++) {
                $this->openPanel(password: 'mal')->assertUnauthorized();
            }

            $this->openPanel()->assertOk();
        }
    }

    /** @return array<string, array{bool, string, string}> */
    public static function closedConfigurations(): array
    {
        return [
            'switch off with the right credentials' => [false, 'operador-de-prueba', 'hash'],
            'no user' => [true, '', 'hash'],
            'no hash' => [true, 'operador-de-prueba', ''],
            'neither' => [true, '', ''],
        ];
    }

    /** Without the switch, the user or the hash, the route does not exist: 404 with the body of any unknown route, and the credentials are not even looked at. */
    #[DataProvider('closedConfigurations')]
    public function test_the_panel_fails_closed(bool $enabled, string $user, string $hash): void
    {
        $this->enableMonitoring();
        config([
            'monitoring.enabled' => $enabled,
            'monitoring.user' => $user,
            'monitoring.password_hash' => $hash === 'hash' ? password_hash(self::PANEL_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]) : $hash,
        ]);

        $unknown = $this->getJson('/ruta-que-no-existe');
        $panel = $this->openPanel();

        $panel->assertNotFound();
        $this->assertSame($unknown->json('type'), $panel->json('type'));
        $this->assertSame($unknown->json('status'), $panel->json('status'));
        $this->assertSame($unknown->json('title'), $panel->json('title'));
        $this->assertStringNotContainsString('Monitoreo de BarberHub', (string) $panel->getContent());
    }

    /** In production, with the defaults of the repository (switch off, no secret), the panel is never public, with or without credentials. */
    public function test_in_production_the_panel_is_never_public_by_default(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->assertSame('production', $this->app->environment());
        $this->assertFalse(config('monitoring.enabled'), 'The tests run with the switch off, as an environment without MONITOR_ENABLED does.');
        $this->assertStringContainsString("env('MONITOR_ENABLED', false)", (string) file_get_contents(config_path('monitoring.php')), 'The switch is off by default.');

        $this->get('/monitoreo')->assertNotFound();
        $this->openPanel('cualquiera', 'cualquiera')->assertNotFound();
        $this->getJson('/monitoreo')->assertNotFound();
    }

    /** The example of the environment ships the switch off and no real value (SE-02, RNF-24). */
    public function test_the_environment_example_ships_the_panel_closed_and_without_secrets(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^MONITOR_ENABLED=false$/m', $example);
        $this->assertMatchesRegularExpression('/^MONITOR_USER=$/m', $example);
        $this->assertMatchesRegularExpression('/^MONITOR_PASSWORD_HASH=$/m', $example);
    }

    /** The route is outside /api/v1, so it is not in the contract that the web client generates its types from. */
    public function test_the_panel_is_not_in_the_openapi_contract(): void
    {
        $contract = (string) file_get_contents(base_path('openapi/v1.json'));

        $this->assertStringNotContainsString('monitoreo', $contract);
    }
}
