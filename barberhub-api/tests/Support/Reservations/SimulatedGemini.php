<?php

declare(strict_types=1);

namespace Tests\Support\Reservations;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * A simulated Google Gemini for the tests of the reservation by phrase (RF-37): it answers the HTTP client of the real adapter, so the
 * adapter, the circuit breaker and the endpoint run as in production and nothing leaves the process. It lives in the tests only; nothing
 * of `src` knows it, and `Http::preventStrayRequests()` (see `AiTestCase`) makes any call it does not answer fail the test.
 *
 * `answering()` installs the script: one step per call, in order, the last one repeating. A step is an answer of the provider or the
 * failure of the connection (`timeout()`). It also keeps how many calls arrived and the options of each one (the 10 s limit).
 */
final class SimulatedGemini
{
    public const string PATTERN = 'generativelanguage.googleapis.com/*';

    public const string KEY = 'llave-simulada-que-no-debe-salir-en-registros';

    public const string MODEL = 'modelo-simulado';

    /** The failures of availability and of credentials that `failure()` knows, for a data provider. */
    public const array FAILURES = ['rate_limited', 'server_error', 'unavailable', 'gateway_timeout', 'key_refused', 'forbidden', 'timeout'];

    /** The answers that are not what the adapter expects, for a data provider. */
    public const array INVALID_ANSWERS = ['not_json', 'list_not_object', 'wrong_types', 'date_not_iso', 'time_out_of_range', 'unknown_day_part', 'truncated', 'no_candidates', 'blocked_prompt'];

    /** How many calls reached the provider. */
    public int $calls = 0;

    /** @var list<array<string, mixed>> The Guzzle options of every call: `timeout` and `connect_timeout` are the limits of RNF-11. */
    public array $options = [];

    /** @var list<PromiseInterface|Throwable> */
    private array $steps;

    /** @param  list<PromiseInterface|Throwable>  $steps */
    private function __construct(array $steps)
    {
        $this->steps = $steps;
    }

    /** Configure a key and a model, and answer the calls of the adapter with the steps. */
    public static function answering(PromiseInterface|Throwable ...$steps): self
    {
        self::configure();
        $simulated = new self(array_values($steps));
        Http::fake([self::PATTERN => $simulated->handle(...)]);

        return $simulated;
    }

    /** The key and the model of the tests: the real ones never reach a test (phpunit.xml). */
    public static function configure(): void
    {
        config(['ai.gemini.api_key' => self::KEY, 'ai.gemini.model' => self::MODEL]);
    }

    /**
     * A valid answer: Gemini returns, as the text of its only candidate, the fields it read.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function valid(array $fields = []): PromiseInterface
    {
        return Http::response(self::candidate($fields === [] ? '{}' : json_encode($fields, JSON_THROW_ON_ERROR)));
    }

    /** An answer that the adapter must refuse whole (one of `INVALID_ANSWERS`). */
    public static function invalid(string $kind): PromiseInterface
    {
        return Http::response(match ($kind) {
            'not_json' => self::candidate('not json'),
            'list_not_object' => self::candidate('[{"service":"corte"}]'),
            'wrong_types' => self::candidate('{"service": 5, "collaborator": ["Kevin"], "date": 20300305}'),
            'date_not_iso' => self::candidate('{"date": "el martes"}'),
            'time_out_of_range' => self::candidate('{"time": "25:00"}'),
            'unknown_day_part' => self::candidate('{"day_part": "midnight"}'),
            'truncated' => self::candidate('{"service": "cor', 'MAX_TOKENS'),
            'no_candidates' => ['candidates' => []],
            'blocked_prompt' => ['promptFeedback' => ['blockReason' => 'SAFETY']],
            default => throw new InvalidArgumentException("Unknown invalid answer: {$kind}."),
        });
    }

    /** The provider is throttled (429), asking to wait or not. */
    public static function rateLimited(?int $retryAfter = null): PromiseInterface
    {
        return Http::response(['error' => ['message' => 'quota']], 429, $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter]);
    }

    /** The provider fails with a status of its own (5xx) or refuses our key (401, 403). */
    public static function status(int $status): PromiseInterface
    {
        return Http::response(['error' => ['message' => 'simulated']], $status);
    }

    /** The provider does not answer within the 10 s: the client gives up with a connection error, nobody waits. */
    public static function timeout(): ConnectionException
    {
        return new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received');
    }

    /** One failure by name (one of `FAILURES`). */
    public static function failure(string $kind): PromiseInterface|Throwable
    {
        return match ($kind) {
            'rate_limited' => self::rateLimited(),
            'server_error' => self::status(500),
            'unavailable' => self::status(503),
            'gateway_timeout' => self::status(504),
            'key_refused' => self::status(401),
            'forbidden' => self::status(403),
            'timeout' => self::timeout(),
            default => throw new InvalidArgumentException("Unknown failure: {$kind}."),
        };
    }

    /**
     * Answer one call: the next step, or the last one again.
     *
     * @param  array<string, mixed>  $options
     */
    private function handle(Request $request, array $options): PromiseInterface
    {
        $this->calls++;
        $this->options[] = $options;
        $step = count($this->steps) > 1 ? array_shift($this->steps) : ($this->steps[0] ?? self::valid());

        return $step instanceof Throwable ? throw $step : $step;
    }

    /**
     * The body of a Gemini answer whose only candidate has this text.
     *
     * @return array<string, mixed>
     */
    private static function candidate(string $text, string $finish = 'STOP'): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]], 'finishReason' => $finish]]];
    }
}
