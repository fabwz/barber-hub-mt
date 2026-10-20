<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Domain\ValueObjects;

use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\Duration;

final readonly class ServiceDuration
{
    public const int MIN_MINUTES = 10;

    public const int MAX_MINUTES = 180;

    public const int STEP_MINUTES = 5;

    public Duration $duration;

    /** Accept 10 to 180 minutes in steps of 5 (SV-01). */
    public function __construct(int $minutes)
    {
        if ($minutes < self::MIN_MINUTES || $minutes > self::MAX_MINUTES || $minutes % self::STEP_MINUTES !== 0) {
            throw new InvalidValueException('services.duration_invalid', [
                'min' => self::MIN_MINUTES,
                'max' => self::MAX_MINUTES,
                'step' => self::STEP_MINUTES,
            ]);
        }

        $this->duration = Duration::ofMinutes($minutes);
    }

    /** Length of the service in minutes. */
    public function minutes(): int
    {
        return $this->duration->minutes;
    }
}
