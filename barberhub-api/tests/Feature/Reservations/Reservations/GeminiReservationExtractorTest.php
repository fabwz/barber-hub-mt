<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Src\Reservations\Reservations\Application\Contracts\ReservationExtractor;
use Src\Reservations\Reservations\Application\DTOs\ExtractedDayPart;
use Src\Reservations\Reservations\Application\DTOs\ExtractedReservation;
use Src\Reservations\Reservations\Application\DTOs\ExtractionContext;
use Src\Reservations\Reservations\Application\Exceptions\ExtractionInvalidException;
use Src\Reservations\Reservations\Application\Exceptions\ExtractorMisconfiguredException;
use Src\Reservations\Reservations\Application\Exceptions\ExtractorUnavailableException;
use Src\Reservations\Reservations\Infrastructure\Ai\GeminiReservationExtractor;
use Tests\Support\Reservations\FakeReservationExtractor;
use Tests\TestCase;
use Throwable;

/**
 * The Gemini adapter of the reservation extractor (RF-37, #69), with the provider simulated: no test calls Google. It shows what
 * is sent (the schema, the model of the configuration, the context, and neither the key in the URL nor anything of an account),
 * how the answer is validated, how every failure is classified, and that the log never keeps the phrase, the answer or the key.
 */
final class GeminiReservationExtractorTest extends TestCase
{
    private const string BASE = 'https://generativelanguage.googleapis.com/v1beta';

    private const string MODEL = 'test-model-1';

    private const string KEY = 'AIza-test-key-that-must-not-leak';

    private const string PHRASE = 'quiero un corte y barba el sábado en la tarde con Kevin';

    private const string REQUEST_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f99';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $entries = [];

    /** @var array{array<string, mixed>|string, int} */
    private array $stub = [[], 200];

    /** No request leaves the process, and the log is captured. */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Context::add('request_id', self::REQUEST_ID);
        Log::shouldReceive('log')->andReturnUsing(function (string $level, string $message, array $context = []): void {
            $this->entries[] = [$level, $message, $context];
        });
    }

    /** A phrase with everything in it gives every field, as the person said it, with the day already resolved. */
    public function test_it_extracts_every_field(): void
    {
        Http::fake([$this->url() => $this->answer(['service' => 'corte y barba', 'collaborator' => 'Kevin', 'date' => '2026-10-10', 'time' => '15:30', 'day_part' => 'afternoon'])]);

        $extracted = $this->extractor()->extract(self::PHRASE, $this->context());

        $this->assertEquals(new ExtractedReservation('corte y barba', 'Kevin', '2026-10-10', '15:30', ExtractedDayPart::Afternoon), $extracted);
        $this->assertLogged('info', 'ok', 200);
    }

    /** What the phrase does not say is null: absent, null or empty fields are the same. */
    public function test_it_extracts_a_phrase_with_missing_fields(): void
    {
        Http::fake([$this->url() => $this->answer(['service' => 'corte', 'collaborator' => null, 'date' => ' ', 'time' => null])]);

        $extracted = $this->extractor()->extract('un corte', $this->context());

        $this->assertEquals(new ExtractedReservation(service: 'corte'), $extracted);
    }

    /** An empty object is a valid answer: the phrase said nothing usable. */
    public function test_it_accepts_an_answer_with_no_fields(): void
    {
        Http::fake([$this->url() => $this->answer([])]);

        $this->assertEquals(new ExtractedReservation, $this->extractor()->extract('hola', $this->context()));
    }

    /** The request carries the model of the configuration, the schema and the context, the key in a header, and nothing of an account. */
    public function test_the_request_carries_the_schema_the_model_and_the_context(): void
    {
        $options = [];
        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return $this->answer([]);
        });

        $this->extractor()->extract(self::PHRASE, $this->context());

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $body = $request->data();
            $message = json_decode($body['contents'][0]['parts'][0]['text'], true);

            return $request->url() === self::BASE.'/models/'.self::MODEL.':generateContent'
                && $request->method() === 'POST'
                && $request->header('x-goog-api-key') === [self::KEY]
                && ! str_contains($request->url(), self::KEY)
                && $body['generationConfig']['responseMimeType'] === 'application/json'
                && $body['generationConfig']['responseJsonSchema']['type'] === 'object'
                && array_keys($body['generationConfig']['responseJsonSchema']['properties']) === ['service', 'collaborator', 'date', 'time', 'day_part']
                && is_string($body['systemInstruction']['parts'][0]['text'])
                && $message === [
                    'phrase' => self::PHRASE,
                    'today' => '2026-10-07',
                    'weekday' => 'Wednesday',
                    'time_zone' => 'America/Costa_Rica',
                    'services' => ['Corte', 'Corte + barba'],
                    'collaborators' => ['Kevin', 'Mauricio'],
                ];
        });
        $this->assertSame(10, $options['timeout']);
        $this->assertSame(5, $options['connect_timeout']);
    }

    /** Every answer that does not comply is invalid, whole: wrong text, types, lengths, dates, times or end of the candidate. */
    public function test_an_answer_that_does_not_comply_is_invalid(): void
    {
        $answers = [
            'text that is not JSON' => self::candidate('not json'),
            'a JSON list' => self::candidate('[{"service":"corte"}]'),
            'a JSON scalar' => self::candidate('"corte"'),
            'a service that is not a string' => self::candidate('{"service": 5}'),
            'a barber that is a list' => self::candidate('{"collaborator": ["Kevin"]}'),
            'a service too long' => self::candidate(json_encode(['service' => str_repeat('a', 101)], JSON_THROW_ON_ERROR)),
            'a date that is not ISO' => self::candidate('{"date": "el sábado"}'),
            'a date that does not exist' => self::candidate('{"date": "2026-02-30"}'),
            'a date that is a number' => self::candidate('{"date": 20261010}'),
            'a time out of range' => self::candidate('{"time": "25:00"}'),
            'a time without minutes' => self::candidate('{"time": "3pm"}'),
            'a part of the day that is unknown' => self::candidate('{"day_part": "midnight"}'),
            'a candidate that did not finish' => self::candidate('{"service": "corte"}', 'SAFETY'),
            'a candidate cut short' => self::candidate('{"service": "cor', 'MAX_TOKENS'),
            'no candidates' => ['candidates' => []],
            'a blocked prompt' => ['promptFeedback' => ['blockReason' => 'SAFETY']],
            'a candidate with no text' => ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['inlineData' => []]]]]]],
            'a body that is not the expected one' => ['error' => 'strange'],
        ];

        foreach ($answers as $name => $body) {
            $this->assertFailure(ExtractionInvalidException::class, $body, 200, ['warning', 'invalid', 200, 'invalid_answer'], $name);
        }
    }

    /** A 2xx that is not JSON at all is invalid too. */
    public function test_a_body_that_is_not_json_is_invalid(): void
    {
        Http::fake([$this->url() => Http::response('<html>gateway</html>', 200)]);

        $this->assertInstanceOf(ExtractionInvalidException::class, $this->failureOf());
    }

    /** A timeout or a lost connection means the provider is not available. */
    public function test_a_timeout_or_a_lost_connection_is_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'));

        $this->assertInstanceOf(ExtractorUnavailableException::class, $this->failureOf());
        $this->assertLogged('warning', 'unavailable', null, 'connection');
    }

    /** A throttled or failing provider (429 and 5xx) is unavailable. */
    public function test_a_throttled_or_failing_provider_is_unavailable(): void
    {
        foreach ([429, 500, 503, 504] as $status) {
            $this->assertFailure(ExtractorUnavailableException::class, ['error' => ['message' => 'busy']], $status, ['warning', 'unavailable', $status, 'provider_error'], "HTTP {$status}");
        }
    }

    /** Our key refused (401 and 403): it is logged as an error and the message does not hold the key. */
    public function test_refused_credentials_are_logged_as_an_error_and_never_shown(): void
    {
        foreach ([401, 403] as $status) {
            $failure = $this->assertFailure(ExtractorUnavailableException::class, ['error' => ['message' => 'API key not valid: '.self::KEY]], $status, ['error', 'unavailable', $status, 'credentials_refused'], "HTTP {$status}");

            $this->assertStringNotContainsString(self::KEY, $failure->getMessage());
        }
    }

    /** A request the provider rejects (400, 404, 422) is our configuration, not a reason for the degraded mode. */
    public function test_a_rejected_request_is_a_configuration_error(): void
    {
        foreach ([400, 404, 422] as $status) {
            $this->assertFailure(ExtractorMisconfiguredException::class, ['error' => ['message' => 'bad']], $status, ['error', 'misconfigured', $status, 'request_rejected'], "HTTP {$status}");
        }
    }

    /** Without a key the provider is never called: the extractor is unavailable and says so in the log. */
    public function test_without_a_key_it_is_unavailable_and_never_calls_the_provider(): void
    {
        Http::fake();

        $failure = $this->failureOf($this->extractor(key: ''));

        $this->assertInstanceOf(ExtractorUnavailableException::class, $failure);
        Http::assertNothingSent();
        $this->assertLogged('warning', 'unavailable', null, 'no_key');
    }

    /** Without a model the adapter fails with a configuration error and never calls the provider: the model is not in the code. */
    public function test_without_a_model_it_is_a_configuration_error_and_never_calls_the_provider(): void
    {
        Http::fake();

        $this->assertInstanceOf(ExtractorMisconfiguredException::class, $this->failureOf($this->extractor(model: '')));
        Http::assertNothingSent();
        $this->assertLogged('error', 'misconfigured', null, 'no_model');
    }

    /** The log has the model, the result, the length of the phrase, the duration and the request id; not the phrase, the answer or the key. */
    public function test_the_log_keeps_neither_the_phrase_nor_the_answer_nor_the_key(): void
    {
        Http::fake([$this->url() => Http::sequence()
            ->push(self::candidate(json_encode(['service' => 'corte y barba', 'collaborator' => 'Kevin', 'date' => '2026-10-10'], JSON_THROW_ON_ERROR)))
            ->push(['error' => ['message' => self::KEY.' '.self::PHRASE]], 403)]);
        $this->extractor()->extract(self::PHRASE, $this->context());
        $this->failureOf();

        $this->assertCount(2, $this->entries);

        foreach ($this->entries as [, , $context]) {
            $this->assertSame(['provider', 'model', 'result', 'reason', 'status', 'duration_ms', 'phrase_length', 'request_id'], array_keys($context));
            $this->assertSame(self::MODEL, $context['model']);
            $this->assertSame(mb_strlen(self::PHRASE), $context['phrase_length']);
            $this->assertSame(self::REQUEST_ID, $context['request_id']);
            $this->assertIsInt($context['duration_ms']);
        }

        $log = json_encode($this->entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        foreach ([self::KEY, self::PHRASE, 'sábado', 'Kevin', 'corte y barba'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
    }

    /** The port is bound to the adapter of the provider named in config/ai.php, with its key, model and base URL. */
    public function test_the_port_resolves_to_the_adapter_of_the_configuration(): void
    {
        config([
            'ai.gemini.api_key' => self::KEY,
            'ai.gemini.model' => 'other-model',
            'ai.gemini.base_url' => 'https://gemini.test/v9/',
        ]);
        Http::fake(['https://gemini.test/v9/models/other-model:generateContent' => $this->answer(['service' => 'corte'])]);

        $extractor = $this->app->make(ReservationExtractor::class);

        $this->assertInstanceOf(GeminiReservationExtractor::class, $extractor);
        $this->assertSame('corte', $extractor->extract('un corte', $this->context())->service);
        Http::assertSent(fn (Request $request): bool => $request->header('x-goog-api-key') === [self::KEY]);
    }

    /** The default configuration has no model and no key, so the real adapter fails safe. */
    public function test_the_default_configuration_has_neither_key_nor_model(): void
    {
        $this->assertSame('gemini', config('ai.provider'));
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta', config('ai.gemini.base_url'));
        $this->assertSame('', config('ai.gemini.model'));
        $this->assertSame('', config('ai.gemini.api_key'));
    }

    /** A provider without an adapter in the configuration is a configuration error. */
    public function test_a_provider_without_an_adapter_is_a_configuration_error(): void
    {
        config(['ai.provider' => 'nobody']);

        $this->expectException(ExtractorMisconfiguredException::class);

        $this->app->make(ReservationExtractor::class);
    }

    /** The simulated adapter lets a test swap the provider without HTTP and see what it was asked. */
    public function test_a_simulated_extractor_replaces_the_provider(): void
    {
        $fake = new FakeReservationExtractor(new ExtractedReservation(service: 'corte'));
        $this->app->instance(ReservationExtractor::class, $fake);

        $extracted = $this->app->make(ReservationExtractor::class)->extract('un corte', $this->context());

        $this->assertSame('corte', $extracted->service);
        $this->assertSame('un corte', $fake->calls[0][0]);
        Http::assertNothingSent();
    }

    /** The simulated adapter can also fail like the provider does. */
    public function test_a_simulated_extractor_can_fail(): void
    {
        $this->expectException(ExtractorUnavailableException::class);

        (new FakeReservationExtractor(ExtractorUnavailableException::because('simulated')))->extract('x', $this->context());
    }

    /** The adapter of the tests: the key, the model and the base URL are set here, never read from the environment. */
    private function extractor(string $key = self::KEY, string $model = self::MODEL): GeminiReservationExtractor
    {
        return new GeminiReservationExtractor($this->app->make(Factory::class), $key, $model, self::BASE);
    }

    private function url(): string
    {
        return self::BASE.'/models/'.self::MODEL.':generateContent';
    }

    private function context(): ExtractionContext
    {
        return new ExtractionContext(['Corte', 'Corte + barba'], ['Kevin', 'Mauricio'], new DateTimeImmutable('2026-10-07 10:00', new DateTimeZone('America/Costa_Rica')));
    }

    /**
     * A successful answer of Gemini whose text is the fields.
     *
     * @param  array<string, mixed>  $fields
     */
    private function answer(array $fields): mixed
    {
        return Http::response(self::candidate($fields === [] ? '{}' : json_encode($fields, JSON_THROW_ON_ERROR)));
    }

    /** @return array<string, mixed> */
    private static function candidate(string $text, string $finish = 'STOP'): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]], 'finishReason' => $finish]]];
    }

    /**
     * Answer every call with the body and the status, and check that the call fails with the exception and leaves one entry in the log.
     *
     * @param  class-string<Throwable>  $exception
     * @param  array<string, mixed>|string  $body
     * @param  array{string, string, ?int, ?string}  $logged  Level, result, status and reason.
     */
    private function assertFailure(string $exception, array|string $body, int $status, array $logged, string $case): Throwable
    {
        $this->entries = [];
        $this->stub = [$body, $status];
        Http::fake(fn () => Http::response(...$this->stub));

        $failure = $this->failureOf();

        $this->assertInstanceOf($exception, $failure, $case);
        $this->assertLogged(...$logged);

        return $failure;
    }

    /** The exception the call throws, failing the test when it does not throw. */
    private function failureOf(?GeminiReservationExtractor $extractor = null): Throwable
    {
        try {
            ($extractor ?? $this->extractor())->extract(self::PHRASE, $this->context());
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('The extractor was expected to fail.');
    }

    /** The only entry of the log has the level, the result, the status and the reason. */
    private function assertLogged(string $level, string $result, ?int $status, ?string $reason = null): void
    {
        $this->assertCount(1, $this->entries);
        [$loggedLevel, , $context] = $this->entries[0];

        $this->assertSame($level, $loggedLevel);
        $this->assertSame($result, $context['result']);
        $this->assertSame($status, $context['status']);
        $this->assertSame($reason, $context['reason']);
    }
}
