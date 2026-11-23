<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use DateTimeImmutable;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Monolog\LogRecord;
use RuntimeException;
use Src\Reservations\Reservations\Application\DTOs\ExtractionContext;
use Src\Reservations\Reservations\Infrastructure\Ai\GeminiReservationExtractor;
use Src\Shared\Domain\Contracts\CorrelationContext;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\CapturesLogRecords;
use Tests\Support\Shared\CorrelationProbeJob;
use Tests\TestCase;
use Throwable;

/**
 * One identifier follows a notice of a gateway, a queued job (with its retries), a scheduled task and a call to the AI from end to end
 * (RNF-25): the `X-Request-Id` when a request starts it, a new UUID when nothing does. No contact data, secret or raw body reaches the log.
 */
final class CorrelationIdTest extends TestCase
{
    use CapturesLogRecords;
    use RefreshDatabaseAsOwner;

    private const string REQUEST_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f77';

    private const string EMAIL = 'ana.mora@barberhub.test';

    private const string PHONE = '2460-1122';

    private const string PHRASE = 'Corte para ana.mora@barberhub.test, mi telefono es 2460-1122';

    private const string KEY = 'gemini-test-key-do-not-log';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Context::flush();
        $this->captureLogRecords();
    }

    /** A notice of a gateway that comes with its own `X-Request-Id` is logged with that identifier, and its body is not. */
    public function test_a_gateway_notice_is_logged_with_the_request_id(): void
    {
        config(['payments.onvo.webhook_secret' => 'onvo-test-signing-secret']);
        $body = json_encode(['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'customer' => ['email' => self::EMAIL, 'phone' => self::PHONE]], JSON_THROW_ON_ERROR);

        $response = $this->call('POST', '/api/v1/pagos/avisos/onvo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUEST_ID' => self::REQUEST_ID,
            'HTTP_X_ONVO_SIGNATURE' => 't=1,v1='.str_repeat('0', 64),
        ], $body);

        $response->assertUnauthorized()->assertHeader('X-Request-Id', self::REQUEST_ID);
        $records = $this->recordsOf('A notice of ONVO Pay was refused.');
        $this->assertCount(1, $records);
        $this->assertSame(self::REQUEST_ID, $records[0]->extra['correlation_id']);
        $this->assertNothingPrivateIsLogged();
    }

    /** A job queued while serving a request carries its identifier, and the worker restores it for every entry of the job. */
    public function test_a_queued_job_keeps_the_identifier_of_the_request_that_queued_it(): void
    {
        config(['queue.default' => 'database']);
        $this->app->make(CorrelationContext::class)->adopt(self::REQUEST_ID);

        CorrelationProbeJob::dispatch();

        $this->assertSame(self::REQUEST_ID, json_decode((string) DB::table('jobs')->value('payload'), true)['correlation_id']);

        Context::flush();
        Artisan::call('queue:work', ['--once' => true]);

        foreach (['queue.job.started', 'probe.handled', 'queue.job.finished'] as $message) {
            $records = $this->recordsOf($message);
            $this->assertCount(1, $records, $message);
            $this->assertSame(self::REQUEST_ID, $records[0]->extra['correlation_id'], $message);
        }
        $this->assertSame(CorrelationProbeJob::class, $this->recordsOf('queue.job.started')[0]->context['job']);
    }

    /** A job queued with no request and no identifier gets a new UUID when it is queued, and runs with it. */
    public function test_a_job_without_a_request_gets_a_new_identifier(): void
    {
        config(['queue.default' => 'database']);

        CorrelationProbeJob::dispatch();

        $queuedWith = json_decode((string) DB::table('jobs')->value('payload'), true)['correlation_id'];
        $this->assertTrue(Str::isUuid($queuedWith));

        Context::flush();
        Artisan::call('queue:work', ['--once' => true]);

        $this->assertSame($queuedWith, $this->recordsOf('probe.handled')[0]->extra['correlation_id']);
    }

    /** A job queued before the identifier existed, with none in its payload, gets one when it starts. */
    public function test_a_job_queued_before_the_identifier_existed_still_runs(): void
    {
        config(['queue.default' => 'database']);
        CorrelationProbeJob::dispatch();
        $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
        unset($payload['correlation_id'], $payload['illuminate:log:context']);
        DB::table('jobs')->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);

        Context::flush();
        Artisan::call('queue:work', ['--once' => true]);

        $this->assertTrue(Str::isUuid($this->recordsOf('probe.handled')[0]->extra['correlation_id']));
        $this->assertCount(1, $this->recordsOf('queue.job.finished'));
    }

    /** The retry of a job that failed runs with the identifier of the first attempt. */
    public function test_a_retry_keeps_the_identifier(): void
    {
        config(['queue.default' => 'database']);
        $this->app->make(CorrelationContext::class)->adopt(self::REQUEST_ID);
        CorrelationProbeJob::dispatch(failsFirst: true);

        Context::flush();
        Artisan::call('queue:work', ['--once' => true]);
        Context::flush();
        Artisan::call('queue:work', ['--once' => true]);

        $attempts = $this->recordsOf('queue.job.started');
        $this->assertSame([1, 2], array_map(fn (LogRecord $record): int => $record->context['attempt'], $attempts));
        foreach ($attempts as $record) {
            $this->assertSame(self::REQUEST_ID, $record->extra['correlation_id']);
        }
        $this->assertCount(1, $this->recordsOf('queue.job.attempt_failed'));
        $this->assertSame(RuntimeException::class, $this->recordsOf('queue.job.attempt_failed')[0]->context['exception']);
        $this->assertSame(self::REQUEST_ID, $this->recordsOf('queue.job.finished')[0]->extra['correlation_id']);
    }

    /** A scheduled task starts a new identifier of its own, and a command that starts with none gets one. */
    public function test_a_scheduled_task_and_a_command_start_their_own_identifier(): void
    {
        // Laravel does not reroute the events of Symfony while it runs tests, so the one of a command starting is sent by hand.
        event(new CommandStarting('list', new ArrayInput([]), new NullOutput));
        $forTheCommand = Context::get('correlation_id');
        $this->assertTrue(Str::isUuid($forTheCommand));

        $task = $this->app->make(Schedule::class)->call(static fn (): null => null);
        event(new ScheduledTaskStarting($task));

        $forTheTask = Context::get('correlation_id');
        $this->assertTrue(Str::isUuid($forTheTask));
        $this->assertNotSame($forTheCommand, $forTheTask);
    }

    /** A call to the AI is logged with the identifier and without the phrase, the answer or the key. */
    public function test_a_call_to_the_ai_is_logged_with_the_identifier_and_without_the_phrase(): void
    {
        $this->app->make(CorrelationContext::class)->adopt(self::REQUEST_ID);
        Http::fake(['*' => Http::response(['error' => ['message' => self::KEY.' '.self::PHRASE]], 403)]);
        $extractor = new GeminiReservationExtractor($this->app->make(Factory::class), self::KEY, 'gemini-test', 'https://ai.test/v1');

        try {
            $extractor->extract(self::PHRASE, new ExtractionContext(['Corte'], ['Kevin'], new DateTimeImmutable('2030-03-01 10:00')));
        } catch (Throwable) {
            // The failure is what is being logged.
        }

        $records = $this->recordsOf('The reservation extractor was called.');
        $this->assertCount(1, $records);
        $this->assertSame(self::REQUEST_ID, $records[0]->extra['correlation_id']);
        $this->assertNothingPrivateIsLogged();
    }

    /** Neither contact data, nor the phrase of the customer, nor a key reaches the log. */
    private function assertNothingPrivateIsLogged(): void
    {
        $logged = $this->allLoggedText();

        $this->assertNotSame('', $logged);
        foreach ([self::EMAIL, self::PHONE, self::PHRASE, self::KEY, 'onvo-test-signing-secret', 'ana.mora'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $logged);
        }
    }
}
