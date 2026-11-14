<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Src\Payments\Payments\Application\DTOs\PaymentInput;
use Src\Payments\Payments\Application\DTOs\PayVoucherCommand;
use Src\Payments\Payments\Infrastructure\Http\PaymentNames;

#[SchemaName('ComprarBono')]
final class PayVoucherRequest extends ChargeBodyRequest
{
    /** Map the validated payload and the person of the session to the use case input; what the gateway does not ask for stays out. The amount is the price of the voucher and never comes from here. */
    public function toCommand(string $voucherDefinitionId): PayVoucherCommand
    {
        // The type arrives as "0" or 0; the command carries it as text and the provider adapter converts it to what that provider documents.
        $identificationType = $this->asks(PaymentInput::IdentificationType) ? $this->input('tipo_identificacion') : null;

        return new PayVoucherCommand(
            (string) $this->user()?->getAuthIdentifier(),
            $voucherDefinitionId,
            PaymentNames::methodOf($this->string('metodo')->toString()),
            $this->asks(PaymentInput::CardToken) ? $this->input('token') : null,
            $this->asks(PaymentInput::Phone) ? $this->input('telefono') : null,
            $this->asks(PaymentInput::Identification) ? $this->input('identificacion') : null,
            $identificationType === null ? null : (string) $identificationType,
        );
    }
}
