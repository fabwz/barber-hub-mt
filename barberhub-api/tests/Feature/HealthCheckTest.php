<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
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
        yield 'nothing fails' => [true, true, 200, ['estado' => 'ok']];
        yield 'only the database fails' => [false, true, 503, ['estado' => 'fallo']];
        yield 'only the queue fails' => [true, false, 503, ['estado' => 'fallo']];
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
