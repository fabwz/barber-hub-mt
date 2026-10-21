<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Infrastructure\Persistence\Queries;

use Src\Catalog\Services\Application\Contracts\PublicServiceReader;
use Src\Catalog\Services\Application\DTOs\PublicService;
use Src\Catalog\Services\Domain\ValueObjects\ServicePrice;
use Src\Catalog\Services\Infrastructure\Persistence\Models\ServiceModel;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class EloquentPublicServiceReader implements PublicServiceReader
{
    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /**
     * Read the active services of the current barbershop with one query; the row-level policy is the second barrier.
     *
     * @return list<PublicService>
     */
    public function activeServices(): array
    {
        $services = ServiceModel::query()
            ->where('barbershop_id', $this->context->current()->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return array_values($services->map($this->toPublicService(...))->all());
    }

    /** Read one active service of the current barbershop with one query. */
    public function activeService(string $serviceId): ?PublicService
    {
        $service = ServiceModel::query()
            ->where('barbershop_id', $this->context->current()->value)
            ->where('is_active', true)
            ->whereKey($serviceId)
            ->first();

        return $service === null ? null : $this->toPublicService($service);
    }

    /** Split the stored price into the base and the VAT the customer sees. */
    private function toPublicService(ServiceModel $service): PublicService
    {
        $price = new ServicePrice($service->price);

        return new PublicService(
            $service->id,
            $service->name,
            $service->duration_minutes,
            $price->colones(),
            $price->base()->colones,
            $price->tax()->colones,
        );
    }
}
