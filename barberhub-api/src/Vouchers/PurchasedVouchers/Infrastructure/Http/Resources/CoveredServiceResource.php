<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\CoveredService;

/**
 * @property-read CoveredService $resource
 */
#[SchemaName('ServicioCubierto')]
final class CoveredServiceResource extends JsonResource
{
    /** Wrap a service a voucher covers. */
    public function __construct(private readonly CoveredService $service)
    {
        parent::__construct($service);
    }

    /**
     * Expose the service with the name it has now.
     *
     * @return array{id: string, nombre: string}
     */
    public function toArray(Request $request): array
    {
        return [
            /** Identificador del servicio. */
            'id' => $this->service->id,
            /** Nombre actual del servicio. Ejemplo: Corte clásico. */
            'nombre' => $this->service->name,
        ];
    }
}
