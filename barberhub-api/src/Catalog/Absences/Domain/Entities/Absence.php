<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Domain\Entities;

use DateTimeImmutable;
use Src\Catalog\Absences\Domain\Exceptions\InvalidAbsenceException;
use Src\Catalog\Absences\Domain\ValueObjects\AbsenceId;
use Src\Catalog\Absences\Domain\ValueObjects\AbsenceReason;
use Src\Catalog\Absences\Domain\ValueObjects\CollaboratorId;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class Absence
{
    /** Hold the full state of an absence. */
    private function __construct(
        public AbsenceId $id,
        public CollaboratorId $collaboratorId,
        public TimeInterval $interval,
        public ?AbsenceReason $reason,
    ) {}

    /** Block the agenda of a collaborator for an interval that has not ended yet at the instant given (RF-07). */
    public static function create(AbsenceId $id, CollaboratorId $collaboratorId, TimeInterval $interval, ?AbsenceReason $reason, DateTimeImmutable $now): self
    {
        if ($interval->end <= $now) {
            throw InvalidAbsenceException::alreadyEnded();
        }

        return new self($id, $collaboratorId, $interval, $reason);
    }

    /** Rebuild an absence from its stored state, even if it already ended. */
    public static function reconstitute(AbsenceId $id, CollaboratorId $collaboratorId, TimeInterval $interval, ?AbsenceReason $reason): self
    {
        return new self($id, $collaboratorId, $interval, $reason);
    }
}
