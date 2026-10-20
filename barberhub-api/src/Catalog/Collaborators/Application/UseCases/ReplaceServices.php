<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\UseCases;

use Src\Catalog\Collaborators\Application\AttachAccounts;
use Src\Catalog\Collaborators\Application\Contracts\BarbershopServices;
use Src\Catalog\Collaborators\Application\DTOs\CollaboratorWithAccount;
use Src\Catalog\Collaborators\Application\DTOs\ReplaceServicesCommand;
use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Catalog\Collaborators\Domain\Exceptions\UnknownServiceException;
use Src\Catalog\Collaborators\Domain\ValueObjects\CollaboratorId;
use Src\Catalog\Collaborators\Domain\ValueObjects\OfferedServiceId;

final readonly class ReplaceServices
{
    /** Receive the collaborator repository and the barbershop services ports. */
    public function __construct(
        private CollaboratorRepository $collaborators,
        private BarbershopServices $services,
        private AttachAccounts $attachAccounts,
    ) {}

    /**
     * Replace the services a collaborator offers; every one must exist in the barbershop (CO-01).
     *
     * @throws CollaboratorNotFoundException
     * @throws UnknownServiceException
     */
    public function execute(ReplaceServicesCommand $command): CollaboratorWithAccount
    {
        $collaborator = $this->collaborators->find(new CollaboratorId($command->collaboratorId))
            ?? throw CollaboratorNotFoundException::create();

        $existing = $this->services->existingAmong($command->serviceIds);

        foreach ($command->serviceIds as $index => $serviceId) {
            if (! in_array($serviceId, $existing, true)) {
                throw UnknownServiceException::at($index);
            }
        }

        $collaborator->offer(array_map(fn (string $id): OfferedServiceId => new OfferedServiceId($id), $command->serviceIds));
        $this->collaborators->save($collaborator);

        return $this->attachAccounts->toOne($collaborator);
    }
}
