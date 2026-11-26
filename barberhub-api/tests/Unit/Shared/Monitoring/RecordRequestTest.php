<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Shared\Monitoring\Application\UseCases\RecordHealthCheck;
use Src\Shared\Monitoring\Application\UseCases\RecordRequest;
use Tests\Support\Monitoring\RecordingHealthChecksWriter;
use Tests\Support\Monitoring\RecordingRequestStatsWriter;
use Tests\Support\Shared\MutableClock;

/** What a request or a health answer becomes before it is stored: the closed list of values, normalized (RNF-15, RNF-24, SE-03). */
final class RecordRequestTest extends TestCase
{
    private MutableClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new MutableClock(new DateTimeImmutable('2030-03-01 08:15:42.123456', new DateTimeZone('America/Costa_Rica')));
    }

    /** The request goes into the minute it finished in, with the seconds at zero, and keeps the pattern of its route. */
    public function test_a_request_is_recorded_in_its_minute_with_the_pattern_of_its_route(): void
    {
        $writer = new RecordingRequestStatsWriter;

        (new RecordRequest($this->clock, $writer))->execute('get', 'api/v1/barberias/{barberia}/reservas', 201, null, 37);

        $this->assertSame([['2030-03-01 08:15:00', 'GET', 'api/v1/barberias/{barberia}/reservas', 2, '', 37]], $writer->calls);
    }

    /** Whatever the status, the class is 1 to 5, and a negative duration cannot happen. */
    public function test_the_status_class_and_the_duration_are_kept_in_range(): void
    {
        $writer = new RecordingRequestStatsWriter;
        $record = new RecordRequest($this->clock, $writer);

        $record->execute('GET', 'salud', 503, null, -5);
        $record->execute('GET', 'salud', 99, null, 1);
        $record->execute('GET', 'salud', 999, null, 1);

        $this->assertSame([5, 1, 5], array_map(fn (array $call): int => $call[3], $writer->calls));
        $this->assertSame(0, $writer->calls[0][5]);
    }

    /** A request that matched no route, or a method nobody invented, cannot create a row per invented value. */
    public function test_an_unknown_route_or_method_is_grouped(): void
    {
        $writer = new RecordingRequestStatsWriter;
        $record = new RecordRequest($this->clock, $writer);

        $record->execute('PROPFIND', null, 404, null, 1);
        $record->execute('GET', '', 404, null, 1);

        $this->assertSame(['OTHER', RecordRequest::NO_ROUTE], [$writer->calls[0][1], $writer->calls[0][2]]);
        $this->assertSame(RecordRequest::NO_ROUTE, $writer->calls[1][2]);
    }

    /** The route and the exception class are cut to the size of their columns. */
    public function test_long_values_are_cut_to_the_size_of_the_columns(): void
    {
        $writer = new RecordingRequestStatsWriter;

        (new RecordRequest($this->clock, $writer))->execute('GET', str_repeat('a', 500), 500, str_repeat('E', 500), 1);

        $this->assertSame(200, strlen($writer->calls[0][2]));
        $this->assertSame(200, strlen($writer->calls[0][4]));
    }

    /** The health answer goes into its minute and keeps which dependency worked. */
    public function test_a_health_answer_is_recorded_in_its_minute(): void
    {
        $writer = new RecordingHealthChecksWriter;

        (new RecordHealthCheck($this->clock, $writer))->execute(true, false, 'degradada');

        $this->assertSame([['2030-03-01 08:15:00', '2030-03-01 08:15:42', true, false, 'degradada']], $writer->calls);
    }
}
