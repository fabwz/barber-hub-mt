<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Catalog\Barbershops\Application\DTOs\UpdatePaymentPolicyCommand;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentGateway;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentPolicy;
use Src\Catalog\Barbershops\Infrastructure\Http\PaymentModeNames;

#[SchemaName('ActualizarCobro')]
final class UpdatePaymentPolicyRequest extends FormRequest
{
    /**
     * Validation rules for a partial update of the payment policy (PA-01, PA-02, CA-01). The rules that relate the fields to
     * each other (the percentage with a deposit, the gateway with a charge) belong to the policy and answer 422 as well.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            /** `sin_pago`, `deposito` o `total`. */
            'modalidad' => ['sometimes', 'required', 'string', Rule::in(PaymentModeNames::names())],
            /** Entre 10 y 100; obligatorio con `deposito` y no admitido en las otras modalidades. */
            'porcentaje_deposito' => ['sometimes', 'nullable', 'integer', 'between:'.PaymentPolicy::MIN_DEPOSIT_PERCENTAGE.','.PaymentPolicy::MAX_DEPOSIT_PERCENTAGE],
            /** Horas de cancelación gratuita antes de la reserva, de 0 a 720. */
            'plazo_cancelacion_horas' => ['sometimes', 'required', 'integer', 'between:'.PaymentPolicy::MIN_DEADLINE_HOURS.','.PaymentPolicy::MAX_DEADLINE_HOURS],
            /** `onvo` o `paypal`; obligatoria con `deposito` y `total`. */
            'pasarela' => ['sometimes', 'required', 'string', Rule::enum(PaymentGateway::class)],
        ];
    }

    /** Map the validated payload to the use case input; a field that did not come keeps its current value. */
    public function toCommand(): UpdatePaymentPolicyCommand
    {
        $data = $this->validated();

        return new UpdatePaymentPolicyCommand(
            isset($data['modalidad']) ? PaymentModeNames::mode((string) $data['modalidad'])->value : null,
            isset($data['porcentaje_deposito']) ? (int) $data['porcentaje_deposito'] : null,
            isset($data['plazo_cancelacion_horas']) ? (int) $data['plazo_cancelacion_horas'] : null,
            isset($data['pasarela']) ? (string) $data['pasarela'] : null,
        );
    }
}
