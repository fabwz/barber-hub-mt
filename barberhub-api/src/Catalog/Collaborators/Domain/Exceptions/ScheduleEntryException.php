<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\Exceptions;

use Src\Catalog\Collaborators\Domain\ValueObjects\ScheduleSection;
use Src\Shared\Domain\Exceptions\InvalidValueException;

/** A weekly schedule that cannot be accepted because of one entry, which the API points at by its list and position. */
abstract class ScheduleEntryException extends InvalidValueException
{
    /** The list the rejected entry belongs to. */
    final public function section(): ScheduleSection
    {
        return ScheduleSection::from((string) $this->parameters()['section']);
    }

    /** Position of the rejected entry in its list, counted from zero. */
    final public function index(): int
    {
        return (int) $this->parameters()['index'];
    }

    /** Build the exception for the entry at a position of a list. */
    protected static function atEntry(string $translationKey, ScheduleSection $section, int $index): static
    {
        return new static($translationKey, ['section' => $section->value, 'index' => $index]);
    }
}
