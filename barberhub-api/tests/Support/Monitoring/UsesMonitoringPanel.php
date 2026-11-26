<?php

declare(strict_types=1);

namespace Tests\Support\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\Shared\MutableClock;

/** Turns the monitoring on with test credentials and a clock the test can move, and opens the panel as the person who knows them. */
trait UsesMonitoringPanel
{
    protected const string PANEL_USER = 'operador-de-prueba';

    protected const string PANEL_PASSWORD = 'clave-solo-para-pruebas-9731';

    private MutableClock $monitoringClock;

    /** Turn the recording and the panel on, with the credentials of the tests. */
    protected function enableMonitoring(bool $withCredentials = true): void
    {
        $this->monitoringClock = new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica')));
        $this->app->instance(Clock::class, $this->monitoringClock);

        config([
            'monitoring.enabled' => true,
            'monitoring.user' => $withCredentials ? self::PANEL_USER : '',
            'monitoring.password_hash' => $withCredentials ? password_hash(self::PANEL_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]) : '',
        ]);
    }

    /**
     * Open the panel with HTTP Basic credentials (the right ones by default).
     *
     * @return TestResponse<Response>
     */
    protected function openPanel(string $user = self::PANEL_USER, string $password = self::PANEL_PASSWORD): TestResponse
    {
        return $this->call('GET', '/monitoreo', server: ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => $password]);
    }
}
