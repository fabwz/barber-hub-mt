<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\UseCases;

use Src\Catalog\Barbershops\Application\DTOs\UpdateBarbershopFeaturesCommand;
use Src\Catalog\Barbershops\Domain\Contracts\BarbershopRepository;
use Src\Catalog\Barbershops\Domain\Exceptions\BarbershopNotFoundException;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopFeatures;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class UpdateBarbershopFeatures
{
    /** Receive the barbershop repository and the context that tells which barbershop is current. */
    public function __construct(private BarbershopRepository $barbershops, private BarbershopContext $context) {}

    /**
     * Switch the vouchers and the waitlist of the current barbershop on or off (RF-09). A field that does not come keeps its value.
     * The change applies to the operations that start from now on: it never cancels a voucher already bought or an offer in course.
     * The technical record of the change (SE-04, #51) does not exist yet.
     */
    public function execute(UpdateBarbershopFeaturesCommand $command): BarbershopFeatures
    {
        $barbershop = $this->barbershops->find($this->context->current()) ?? throw BarbershopNotFoundException::create();
        $current = $barbershop->features;

        $features = BarbershopFeatures::of(
            $command->vouchersEnabled ?? $current->vouchersEnabled,
            $command->waitlistEnabled ?? $current->waitlistEnabled,
        );

        $this->barbershops->save($barbershop->withFeatures($features));

        return $features;
    }
}
