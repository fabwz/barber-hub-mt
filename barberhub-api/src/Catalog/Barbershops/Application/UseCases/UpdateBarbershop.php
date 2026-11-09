<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\UseCases;

use Src\Catalog\Barbershops\Application\DTOs\UpdateBarbershopCommand;
use Src\Catalog\Barbershops\Domain\Contracts\BarbershopRepository;
use Src\Catalog\Barbershops\Domain\Entities\Barbershop;
use Src\Catalog\Barbershops\Domain\Exceptions\BarbershopNotFoundException;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\Phone;

final readonly class UpdateBarbershop
{
    /** Receive the barbershop repository and the context that tells which barbershop is current. */
    public function __construct(private BarbershopRepository $barbershops, private BarbershopContext $context) {}

    /**
     * Change the data of the current barbershop (RF-01): name, city, phone, address, contact email and social links. A field
     * that does not come keeps its value. The public link never changes, whatever the new name (MT-07, RF-02).
     */
    public function execute(UpdateBarbershopCommand $command): Barbershop
    {
        $barbershop = $this->barbershops->find($this->context->current()) ?? throw BarbershopNotFoundException::create();

        $barbershop = $barbershop
            ->withDetails(
                $command->name === null ? $barbershop->name : new BarbershopName($command->name),
                $command->city === null ? $barbershop->city : new City($command->city),
                $command->phone === null ? $barbershop->phone : new Phone($command->phone),
            )
            ->withProfile($barbershop->profile->changed($command->profile));

        $this->barbershops->save($barbershop);

        return $barbershop;
    }
}
