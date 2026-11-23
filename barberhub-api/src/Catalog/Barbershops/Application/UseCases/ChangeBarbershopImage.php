<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\UseCases;

use Src\Catalog\Barbershops\Application\DTOs\ChangeBarbershopImageCommand;
use Src\Catalog\Barbershops\Domain\Contracts\BarbershopRepository;
use Src\Catalog\Barbershops\Domain\Exceptions\BarbershopNotFoundException;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopImageKind;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopImages;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\BarbershopFileStorage;
use Throwable;

final readonly class ChangeBarbershopImage
{
    /** Receive the barbershop repository, the context that tells which barbershop is current and the storage of its files. */
    public function __construct(
        private BarbershopRepository $barbershops,
        private BarbershopContext $context,
        private BarbershopFileStorage $files,
    ) {}

    /**
     * Keep the new logo or banner in the space of the current barbershop and delete the one it replaces (RF-46, MT-05). The type and the
     * size were checked on the content of the file before this runs. If the row cannot be saved, the new file is removed, so nothing is left orphaned.
     */
    public function execute(ChangeBarbershopImageCommand $command): BarbershopImages
    {
        $kind = BarbershopImageKind::from($command->kind);
        $barbershop = $this->barbershops->find($this->context->current()) ?? throw BarbershopNotFoundException::create();

        $path = $this->files->store($kind->value, $command->imagePath, $command->extension);

        try {
            $this->barbershops->save($barbershop->withImage($kind, $path));
        } catch (Throwable $exception) {
            $this->files->delete($path);

            throw $exception;
        }

        $previous = $barbershop->images->pathOf($kind);

        if ($previous !== null) {
            $this->files->delete($previous);
        }

        return $barbershop->images->replacing($kind, $path);
    }
}
