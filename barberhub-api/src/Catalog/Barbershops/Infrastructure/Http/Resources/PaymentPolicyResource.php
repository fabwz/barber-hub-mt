<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentPolicy;
use Src\Catalog\Barbershops\Infrastructure\Http\PaymentModeNames;

/**
 * @property-read PaymentPolicy $resource
 */
#[SchemaName('Cobro')]
final class PaymentPolicyResource extends JsonResource
{
    /** Wrap the payment policy of a barbershop. */
    public function __construct(private readonly PaymentPolicy $policy)
    {
        parent::__construct($policy);
    }

    /**
     * Expose the policy: mode, percentage of the deposit (null unless it is a deposit), free-cancellation deadline and gateway (null if none).
     *
     * @return array{modalidad: string, porcentaje_deposito: int|null, plazo_cancelacion_horas: int, pasarela: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'modalidad' => PaymentModeNames::of($this->policy->mode),
            'porcentaje_deposito' => $this->policy->depositPercentage,
            'plazo_cancelacion_horas' => $this->policy->cancellationDeadlineHours,
            'pasarela' => $this->policy->gateway?->value,
        ];
    }
}
