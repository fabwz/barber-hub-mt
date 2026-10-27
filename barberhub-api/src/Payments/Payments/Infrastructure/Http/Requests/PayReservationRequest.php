<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Payments\Payments\Application\DTOs\PayCommand;
use Src\Payments\Payments\Infrastructure\Http\PaymentNames;

#[SchemaName('PagarReserva')]
final class PayReservationRequest extends FormRequest
{
    /** The identification types the provider admits for SINPE Móvil: national, resident, state entity, legal, autonomous institution, diplomat and foreign. */
    private const array IDENTIFICATION_TYPES = ['0', '1', '2', '3', '4', '5', '9'];

    /**
     * Validation rules for paying a reservation (RF-20). The body never carries the amount nor the barbershop: the amount is
     * calculated from the price of the reservation and the policy, and the card number never arrives (PCI), only the token.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $isCard = fn (): bool => $this->input('metodo') === 'tarjeta';
        $isSinpe = fn (): bool => $this->input('metodo') === 'sinpe_movil';

        return [
            /** Medio de pago: `tarjeta` o `sinpe_movil`. */
            'metodo' => ['required', 'string', Rule::in(PaymentNames::methodNames())],
            /** Con `tarjeta`: identificador del método de pago que el navegador creó con la llave pública de la pasarela. Nunca el número de la tarjeta. */
            'token' => [Rule::requiredIf($isCard), 'nullable', 'string', 'max:255'],
            /** Con `sinpe_movil`: teléfono desde el que se hace la transferencia, por ejemplo `+50688888888`. */
            'telefono' => [Rule::requiredIf($isSinpe), 'nullable', 'string', 'max:20'],
            /** Con `sinpe_movil`: identificación de la persona, con los guiones de su tipo, por ejemplo `01-1393-1919`. */
            'identificacion' => [Rule::requiredIf($isSinpe), 'nullable', 'string', 'max:30'],
            /** Con `sinpe_movil`: tipo de identificación: `0` nacional, `1` residente, `2` entidad estatal, `3` jurídica, `4` institución autónoma, `5` diplomático o `9` extranjero. */
            'tipo_identificacion' => [Rule::requiredIf($isSinpe), 'nullable', 'string', Rule::in(self::IDENTIFICATION_TYPES)],
        ];
    }

    /** Map the validated payload and the person of the session to the use case input. */
    public function toCommand(string $reservationId): PayCommand
    {
        return new PayCommand(
            (string) $this->user()?->getAuthIdentifier(),
            $reservationId,
            PaymentNames::methodOf($this->string('metodo')->toString()),
            $this->input('token'),
            $this->input('telefono'),
            $this->input('identificacion'),
            $this->input('tipo_identificacion'),
        );
    }
}
