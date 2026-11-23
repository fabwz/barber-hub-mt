<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;

/** Keeps every record the application writes, with the `extra` that the context of Laravel adds (the correlation identifier among it). */
trait CapturesLogRecords
{
    private TestHandler $logHandler;

    /** Start keeping the records of the default channel. */
    protected function captureLogRecords(): void
    {
        $this->logHandler = new TestHandler;
        $channel = Log::channel();
        $logger = $channel instanceof IlluminateLogger ? $channel->getLogger() : null;

        if (! $logger instanceof Logger) {
            $this->fail('The default log channel is not a Monolog logger.');
        }

        $logger->pushHandler($this->logHandler);
    }

    /**
     * The records with this message, in order.
     *
     * @return list<LogRecord>
     */
    protected function recordsOf(string $message): array
    {
        return array_values(array_filter($this->logHandler->getRecords(), fn (LogRecord $record): bool => $record->message === $message));
    }

    /** Everything that was logged, message, context and extra, as one text. */
    protected function allLoggedText(): string
    {
        return implode("\n", array_map(
            fn (LogRecord $record): string => $record->message.json_encode([$record->context, $record->extra], JSON_PARTIAL_OUTPUT_ON_ERROR),
            $this->logHandler->getRecords(),
        ));
    }
}
