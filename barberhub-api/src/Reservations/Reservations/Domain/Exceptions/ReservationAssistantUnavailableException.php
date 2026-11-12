<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;
use Src\Shared\Domain\Exceptions\HasRetryAfter;

final class ReservationAssistantUnavailableException extends BusinessRuleException implements HasRetryAfter
{
    private ?int $retryAfterSeconds = null;

    /** The reservation by phrase cannot answer now, so the person books with the usual form (IA-05); `$retryAfterSeconds` is when the provider may be back, if known. */
    public static function create(?int $retryAfterSeconds = null): self
    {
        $exception = new self('reservations.assistant_unavailable');
        $exception->retryAfterSeconds = $retryAfterSeconds;

        return $exception;
    }

    /** The stable type 503 of the API. */
    public function rule(): string
    {
        return 'servicio-no-disponible';
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
