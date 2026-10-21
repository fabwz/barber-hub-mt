<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\BusinessRuleException;

final class StaffCannotBeCustomerException extends BusinessRuleException
{
    /** Build the exception for a person that already holds the role of staff in the barbershop (UP-07, UP-09). */
    public static function create(): self
    {
        return new self('identity.staff_cannot_be_customer');
    }

    /** An owner or a collaborator of the barbershop cannot also be its customer: the API answers 403. */
    public function rule(): string
    {
        return 'prohibido';
    }
}
