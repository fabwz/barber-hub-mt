<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Console;

use Illuminate\Console\Command;
use Src\Reservations\Reservations\Application\Contracts\ReservationExtractor;
use Src\Reservations\Reservations\Application\DTOs\ExtractionContext;
use Src\Shared\Domain\Contracts\Clock;
use Throwable;

/**
 * A manual check of the language provider in development: it really calls the adapter chosen in config/ai.php with the current
 * configuration and shows what it extracted and how long it took. It reads no barbershop and writes nothing: the names the
 * provider is told come from the options. It refuses to run in production and without a key. No automated test uses it.
 */
final class ExtractReservationCommand extends Command
{
    protected $signature = 'ai:extract
        {phrase : The phrase to send to the provider, for example "quiero un corte mañana a las 3 con Kevin"}
        {--service=* : A service name to tell the provider; repeat it for more}
        {--collaborator=* : A barber name to tell the provider; repeat it for more}';

    protected $description = 'Send a phrase to the real language provider and show the structured result (development only)';

    /** Run the extraction against the provider of the current configuration. */
    public function handle(ReservationExtractor $extractor, Clock $clock): int
    {
        if ($this->laravel->isProduction()) {
            $this->components->error('This command does not run in production: it calls the real provider.');

            return self::FAILURE;
        }

        $provider = (string) config('ai.provider');

        if ((string) config("ai.{$provider}.api_key") === '') {
            $this->components->error("There is no key for the provider \"{$provider}\": set it in the environment first.");

            return self::FAILURE;
        }

        $context = new ExtractionContext($this->names('service'), $this->names('collaborator'), $clock->now());
        $started = hrtime(true);

        try {
            $result = $extractor->extract((string) $this->argument('phrase'), $context);
        } catch (Throwable $exception) {
            $this->components->error(class_basename($exception).': '.$exception->getMessage());

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

        return self::SUCCESS;
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
