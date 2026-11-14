<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Src\Payments\Payments\Application\DTOs\PayCommand;
use Src\Payments\Payments\Application\DTOs\PaymentInput;
use Src\Payments\Payments\Infrastructure\Http\PaymentNames;

#[SchemaName('PagarReserva')]
final class PayReservationRequest extends ChargeBodyRequest
{
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
