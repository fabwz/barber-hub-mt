<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;

#[SchemaName('AceptarOferta')]
final class AcceptOfferRequest extends FormRequest
{
    /**
     * Validation rules for accepting an offer (RF-32). The barbershop and the offer are the ones of the URL, and the customer is the person of the
     * session: the body only says how the person pays.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** Verdadero para pagar con una sesión de un bono de la persona en esta barbería, sin depósito (RF-28): la reserva queda `confirmada` de inmediato. Por omisión es falso: la reserva sigue la modalidad de pago de la barbería. */
            'con_bono' => ['sometimes', 'boolean'],
        ];
    }

    /** Whether the person pays with a session of a voucher. */
    public function withVoucher(): bool
    {
        return $this->boolean('con_bono');
    }
}
