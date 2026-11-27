<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class AbsenceCollaboratorInactiveException extends BusinessRuleException
{
    /** Build the exception for a collaborator who is inactive: only an active one has an agenda to block (RF-07). */
    public static function create(): self
    {
        return new self('absences.collaborator_inactive');
    }

    /** It has no stable type of its own, so the API answers the generic conflict. */
    public function rule(): string
    {
        return 'conflicto';
    }
}
