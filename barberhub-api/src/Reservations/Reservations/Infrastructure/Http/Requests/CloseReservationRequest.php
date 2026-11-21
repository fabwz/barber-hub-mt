<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;

#[SchemaName('CerrarReserva')]
final class CloseReservationRequest extends FormRequest
{
    /** The names of the API of the two outcomes a reservation closes with. */
    private const array OUTCOMES = ['atendida' => ReservationStatus::Completed, 'ausente' => ReservationStatus::NoShow];

    /**
     * Validation rules for closing a reservation (RF-18).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            /** Cómo termina la reserva: `atendida` o `ausente`. */
            'estado' => ['required', 'string', Rule::in(array_keys(self::OUTCOMES))],
        ];
    }

    /** The status the body asks for. */
    public function outcome(): ReservationStatus
    {
        return self::OUTCOMES[$this->string('estado')->toString()];
    }
}
