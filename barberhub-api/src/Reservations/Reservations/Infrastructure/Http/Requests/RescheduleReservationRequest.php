<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Requests;

use DateTimeImmutable;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;

#[SchemaName('ReprogramarReserva')]
final class RescheduleReservationRequest extends FormRequest
{
    private const string START_FORMAT = 'Y-m-d\TH:i:sP';

    /**
     * Validation rules for moving a reservation (RF-15). The barbershop is the one of the URL and the service is the one the
     * reservation already has; the body only names the new space.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** Inicio del nuevo espacio, fecha y hora ISO 8601 con desfase, por ejemplo `2030-03-05T10:00:00-06:00`. */
            'inicio' => ['required', 'date_format:'.self::START_FORMAT],
            /** Identificador del barbero del nuevo espacio; si se omite, la reserva sigue con el mismo barbero. */
            'barbero' => ['sometimes', 'uuid'],
        ];
    }

    /**
     * Messages of the rules that need their own text.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['inicio.date_format' => __('reservations.start_invalid')];
    }

    /** The new start in the time zone of the application. */
    public function startsAt(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->string('inicio')->toString())->setTimezone(new DateTimeZone((string) config('app.timezone')));
    }

    /** The collaborator of the new space, or null to keep the one of the reservation. */
    public function collaboratorId(): ?string
    {
        return $this->has('barbero') ? $this->string('barbero')->toString() : null;
    }
}
