<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;
use Src\Shared\Domain\Exceptions\HasProblemMembers;
use Src\Waitlist\Entries\Domain\ValueObjects\OpenSpace;

final class SpacesAvailableException extends BusinessRuleException implements HasProblemMembers
{
    /** The most spaces the error shows (the same number the reservation offers as alternatives, RE-02). */
    public const int SHOWN = 3;

    /** @var list<OpenSpace> */
    private array $spaces = [];

    /**
     * Build the exception for a combination that has free spaces: the customer books one instead of joining the line (LE-01). It
     * carries the first of them, in order of start.
     *
     * @param  list<OpenSpace>  $spaces
     */
    public static function with(array $spaces): self
    {
        $exception = new self('waitlist.spaces_available');
        $exception->spaces = array_slice($spaces, 0, self::SHOWN);

        return $exception;
    }

    /**
     * The spaces shown.
     *
     * @return list<OpenSpace>
     */
    public function spaces(): array
    {
        return $this->spaces;
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }

    /**
     * The spaces as the API publishes them: the collaborator and the start and end of the service in ISO 8601.
     *
     * @return array{espacios: list<array{barbero: string, inicio: string, fin: string}>}
     */
    public function problemMembers(): array
    {
        return ['espacios' => array_map(fn (OpenSpace $space): array => [
            'barbero' => $space->collaboratorId->value,
            'inicio' => $space->interval->start->format(DATE_ATOM),
            'fin' => $space->interval->end->format(DATE_ATOM),
        ], $this->spaces)];
    }
}
