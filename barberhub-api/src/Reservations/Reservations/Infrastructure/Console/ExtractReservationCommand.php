<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Console;

use Illuminate\Console\Command;
use Src\Reservations\Reservations\Application\Contracts\ReservationExtractor;
use Src\Reservations\Reservations\Application\DTOs\ExtractionContext;
use Src\Reservations\Reservations\Infrastructure\Ai\AiCircuitStore;
use Src\Shared\Domain\Contracts\Clock;
use Throwable;

/**
 * A manual check of the language provider in development: it really calls the adapter chosen in config/ai.php with the current
 * configuration and shows what it extracted and how long it took. It reads no barbershop and writes nothing: the names the
 * provider is told come from the options. It goes through the same path as the endpoint, circuit breaker included, and shows the
 * state of the circuit before and after the call (an open circuit answers at once without calling the provider). It refuses to run
 * in production and without a key. No automated test calls the real provider with it.
 */
final class ExtractReservationCommand extends Command
{
    protected $signature = 'ai:extract
        {phrase : The phrase to send to the provider, for example "quiero un corte mañana a las 3 con Kevin"}
        {--service=* : A service name to tell the provider; repeat it for more}
        {--collaborator=* : A barber name to tell the provider; repeat it for more}';

    protected $description = 'Send a phrase to the real language provider and show the structured result (development only)';

    /** Run the extraction against the provider of the current configuration. */
    public function handle(ReservationExtractor $extractor, Clock $clock, AiCircuitStore $circuit): int
    {
        if ($this->laravel->isProduction()) {
            $this->components->error('This command does not run in production: it calls the real provider.');

            return self::FAILURE;
        }

        $provider = (string) config('ai.provider');

        if ((string) config("ai.{$provider}.api_key") === '') {
            $this->components->error("The provider \"{$provider}\" is not configured (no key): set it in the environment first.");

            return self::FAILURE;
        }

        $this->components->info('Circuit before the call: '.$this->circuitState($circuit, $clock));
        $context = new ExtractionContext($this->names('service'), $this->names('collaborator'), $clock->now());
        $started = hrtime(true);

        try {
            $result = $extractor->extract((string) $this->argument('phrase'), $context);
        } catch (Throwable $exception) {
            $this->components->error(class_basename($exception).': '.$exception->getMessage());
            $this->components->info('Circuit after the call: '.$this->circuitState($circuit, $clock));

            return self::FAILURE;
        }

        $this->table(['field', 'value'], [
            ['service', $result->service ?? '-'],
            ['collaborator', $result->collaborator ?? '-'],
            ['date', $result->date ?? '-'],
            ['time', $result->time ?? '-'],
            ['day part', $result->dayPart->value ?? '-'],
        ]);
        $this->components->info(sprintf('Provider "%s" answered in %d ms (today is %s).', $provider, (int) ((hrtime(true) - $started) / 1_000_000), $context->today->format('Y-m-d')));
        $this->components->info('Circuit after the call: '.$this->circuitState($circuit, $clock));

        return self::SUCCESS;
    }

    /** The state of the circuit in words: closed, open with the seconds that are left, or waiting for its probe. */
    private function circuitState(AiCircuitStore $circuit, Clock $clock): string
    {
        try {
            $state = $circuit->state();
        } catch (Throwable) {
            return 'unknown (the cache is not available, so it behaves as closed)';
        }

        if ($state === null) {
            return 'closed';
        }

        $left = $state['open_until'] - $clock->now()->getTimestamp();

        return $left > 0 ? "open ({$state['reason']}), {$left} s left, the provider is not called" : "half-open ({$state['reason']}), the next call is the probe";
    }

    /**
     * The names given in an option, without blanks.
     *
     * @return list<string>
     */
    private function names(string $option): array
    {
        $names = $this->option($option);

        return array_values(array_filter(array_map(trim(...), is_array($names) ? array_map(strval(...), $names) : []), fn (string $name): bool => $name !== ''));
    }
}
