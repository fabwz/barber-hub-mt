<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;

#[SchemaName('ProponerReserva')]
final class ProposeReservationRequest extends FormRequest
{
    /** Longest phrase accepted: it travels to an external provider, so its size is bounded (RF-37). */
    public const int MAX_PHRASE_LENGTH = 300;

    /**
     * Validation rules for the phrase. The barbershop is the one of the URL and the person is the one of the session.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** Frase libre del cliente, por ejemplo `quiero un corte mañana a las 3 con Kevin`. Hasta 300 caracteres; no se guarda. */
            'frase' => ['required', 'string', 'max:'.self::MAX_PHRASE_LENGTH],
        ];
    }

    /** The phrase as the person wrote it. */
    public function phrase(): string
    {
        return $this->string('frase')->toString();
    }
}
