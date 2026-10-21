<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\UseCases;

use Src\Catalog\Collaborators\Application\AttachAccounts;
use Src\Catalog\Collaborators\Application\DTOs\BookableStaffMember;
use Src\Catalog\Collaborators\Application\DTOs\CollaboratorWithAccount;
use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Domain\Entities\Collaborator;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorNotBookableException;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Catalog\Collaborators\Domain\ValueObjects\CollaboratorId;
use Src\Catalog\Collaborators\Domain\ValueObjects\OfferedServiceId;

final readonly class ListBookableStaff
{
    /** Receive the collaborator repository port and the pairing with their accounts. */
    public function __construct(
        private CollaboratorRepository $collaborators,
        private AttachAccounts $attachAccounts,
    ) {}

    /**
     * Give the names of the active collaborators who offer a service, or only of the one asked for; the availability reads it (CO-01).
     *
     * @return list<BookableStaffMember>
     *
     * @throws CollaboratorNotFoundException when the collaborator is not in the current barbershop
     * @throws CollaboratorNotBookableException when the collaborator is inactive or does not offer the service
     */
    public function execute(string $serviceId, ?string $collaboratorId): array
    {
        $service = new OfferedServiceId($serviceId);

        if ($collaboratorId === null) {
            $collaborators = $this->collaborators->activeOffering($service);
        } else {
            $collaborator = $this->collaborators->find(new CollaboratorId($collaboratorId)) ?? throw CollaboratorNotFoundException::create();

            if (! $collaborator->active || ! $collaborator->offers($service)) {
                throw CollaboratorNotBookableException::create();
            }

            $collaborators = [$collaborator];
        }

        return array_map(
            fn (CollaboratorWithAccount $member): BookableStaffMember => new BookableStaffMember($member->collaborator->id->value, $member->name),
            $this->attachAccounts->to($collaborators),
        );
    }
}
