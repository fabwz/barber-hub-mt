<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Requests;

use DateTimeImmutable;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Reservations\Reservations\Application\DTOs\CreateReservationCommand;

#[SchemaName('CrearReserva')]
final class CreateReservationRequest extends FormRequest
{
    private const string START_FORMAT = 'Y-m-d\TH:i:sP';

    /**
     * Validation rules for a new reservation (RF-11). The barbershop is the one of the URL: the body never names it, nor
     * the customer, who is the person of the session (CL-05).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** Identificador del servicio activo de la barbería. */
            'servicio' => ['required', 'uuid'],
            /** Identificador del barbero, el de un espacio de la consulta de disponibilidad. */
            'barbero' => ['required', 'uuid'],
            /** Inicio del espacio, fecha y hora ISO 8601 con desfase, por ejemplo `2030-03-05T10:00:00-06:00`. */
            'inicio' => ['required', 'date_format:'.self::START_FORMAT],
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

    /** Map the validated payload and the person of the session to the use case input. */
    public function toCommand(): CreateReservationCommand
    {
        return new CreateReservationCommand(
            (string) $this->user()?->getAuthIdentifier(),
            $this->string('servicio')->toString(),
            $this->string('barbero')->toString(),
            new DateTimeImmutable($this->string('inicio')->toString())->setTimezone(new DateTimeZone((string) config('app.timezone'))),
        );
    }
}
