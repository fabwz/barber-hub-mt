<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Http\Requests;

use Closure;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Payments\Payments\Application\DTOs\PayCommand;
use Src\Payments\Payments\Application\DTOs\PaymentInput;
use Src\Payments\Payments\Application\PaymentGatewayResolver;
use Src\Payments\Payments\Infrastructure\Http\PaymentNames;

#[SchemaName('PagarReserva')]
final class PayReservationRequest extends FormRequest
{
    /** The identification types the provider admits for SINPE Móvil: national, resident, state entity, legal, autonomous institution, diplomat and foreign. */
    private const array IDENTIFICATION_TYPES = ['0', '1', '2', '3', '4', '5', '9'];

    /** @var list<PaymentInput>|null What the gateway of the barbershop asks for the method; unknown until `rules()` resolves it. */
    private ?array $required = null;

    /**
     * Validation rules for paying a reservation (RF-20). The body never carries the amount nor the barbershop: the amount is
     * calculated from the price of the reservation and the policy, and the card number never arrives (PCI). Besides `metodo`,
     * only what the gateway of the barbershop asks for that method is required and validated; any other field is dropped, so
     * the request names no gateway. A method the gateway does not admit fails here with its 422 on `metodo`.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $this->required = $this->requiredInput();
        $excluded = fn (PaymentInput $input): Closure => fn (): bool => ! $this->asks($input);
        $asked = fn (PaymentInput $input): Closure => fn (): bool => $this->asks($input);

        return [
            /** Medio de pago: `tarjeta` o `sinpe_movil`. */
            'metodo' => ['required', 'string', Rule::in(PaymentNames::methodNames())],
            /** Obligatorio según la pasarela configurada para la barbería: identificador del método de pago que el navegador creó con la llave pública de la pasarela. Nunca el número de la tarjeta. */
            'token' => [Rule::excludeIf($excluded(PaymentInput::CardToken)), Rule::requiredIf($asked(PaymentInput::CardToken)), 'nullable', 'string', 'max:255'],
            /** Obligatorio según la pasarela configurada para la barbería: teléfono desde el que se hace la transferencia, por ejemplo `+50688888888`. */
            'telefono' => [Rule::excludeIf($excluded(PaymentInput::Phone)), Rule::requiredIf($asked(PaymentInput::Phone)), 'nullable', 'string', 'max:20'],
            /** Obligatorio según la pasarela configurada para la barbería: identificación de la persona, con los guiones de su tipo, por ejemplo `01-1393-1919`. */
            'identificacion' => [Rule::excludeIf($excluded(PaymentInput::Identification)), Rule::requiredIf($asked(PaymentInput::Identification)), 'nullable', 'string', 'max:30'],
            /** Obligatorio según la pasarela configurada para la barbería: tipo de identificación, como `"0"` o `0`: `0` nacional, `1` residente, `2` entidad estatal, `3` jurídica, `4` institución autónoma, `5` diplomático o `9` extranjero. */
            'tipo_identificacion' => [Rule::excludeIf($excluded(PaymentInput::IdentificationType)), Rule::requiredIf($asked(PaymentInput::IdentificationType)), 'nullable', $this->identificationTypeRule(), Rule::in(self::IDENTIFICATION_TYPES)],
        ];
    }

    /**
     * What the gateway of the barbershop asks of the customer for the method of the body; nothing while the method is unknown.
     *
     * @return list<PaymentInput>
     */
    private function requiredInput(): array
    {
        $name = $this->input('metodo');

        if (! is_string($name) || ! in_array($name, PaymentNames::methodNames(), true)) {
            return [];
        }

        $method = PaymentNames::methodOf($name);

        return $this->container->make(PaymentGatewayResolver::class)->forNewCharge($method)->requiredInput($method);
    }

    /** Tell whether the gateway asks for the piece of data. */
    private function asks(PaymentInput $input): bool
    {
        return in_array($input, $this->required ?? [], true);
    }

    /** Only a number or a text can be the type: `true` would pass `in` as "1". */
    private function identificationTypeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value) && ! is_string($value)) {
                $fail('validation.in')->translate();
            }
        };
    }

    /** Map the validated payload and the person of the session to the use case input; what the gateway does not ask for stays out. */
    public function toCommand(string $reservationId): PayCommand
    {
        // The type arrives as "0" or 0; the command carries it as text and the provider adapter converts it to what that provider documents.
        $identificationType = $this->asks(PaymentInput::IdentificationType) ? $this->input('tipo_identificacion') : null;

        return new PayCommand(
            (string) $this->user()?->getAuthIdentifier(),
            $reservationId,
            PaymentNames::methodOf($this->string('metodo')->toString()),
            $this->asks(PaymentInput::CardToken) ? $this->input('token') : null,
            $this->asks(PaymentInput::Phone) ? $this->input('telefono') : null,
            $this->asks(PaymentInput::Identification) ? $this->input('identificacion') : null,
            $identificationType === null ? null : (string) $identificationType,
        );
    }
}
