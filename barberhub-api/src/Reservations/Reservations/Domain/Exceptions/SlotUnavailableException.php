<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Exceptions;

use Src\Reservations\Reservations\Domain\ValueObjects\SlotAlternative;
use Src\Shared\Domain\Exceptions\BusinessRuleException;
use Src\Shared\Domain\Exceptions\HasProblemMembers;

final class SlotUnavailableException extends BusinessRuleException implements HasProblemMembers
{
    /** @var list<SlotAlternative> */
    private array $alternatives = [];

    /** Build the exception for a space that is not available, with no alternatives yet. */
    public static function create(): self
    {
        return new self('reservations.slot_unavailable');
    }

    /**
     * The same error with up to 3 spaces of the same day and service to offer instead (RE-02).
     *
     * @param  list<SlotAlternative>  $alternatives
     */
    public function withAlternatives(array $alternatives): self
    {
        $exception = new self('reservations.slot_unavailable');
        $exception->alternatives = $alternatives;

        return $exception;
    }

    /**
     * The spaces offered instead.
     *
     * @return list<SlotAlternative>
     */
    public function alternatives(): array
    {
        return $this->alternatives;
    }

    /** The space asked for is taken or is not offered: the stable type of RNF-17. */
    public function rule(): string
    {
        return 'espacio-no-disponible';
    }

    /**
     * The alternatives as the API publishes them: the collaborator and the start and end of the service in ISO 8601.
     *
     * @return array{alternativas: list<array{barbero: string, inicio: string, fin: string}>}
     */
    public function problemMembers(): array
    {
        return ['alternativas' => array_map(fn (SlotAlternative $alternative): array => [
            'barbero' => $alternative->collaboratorId->value,
            'inicio' => $alternative->interval->start->format(DATE_ATOM),
            'fin' => $alternative->interval->end->format(DATE_ATOM),
        ], $this->alternatives)];
    }
}
