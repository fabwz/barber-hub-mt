<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Requests;

use DateTimeImmutable;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Reservations\Reservations\Application\DTOs\RegisterAssistedReservationCommand;
use Src\Shared\Domain\ValueObjects\Phone;

#[SchemaName('RegistrarReservaAsistida')]
final class RegisterAssistedReservationRequest extends FormRequest
{
    private const string START_FORMAT = 'Y-m-d\TH:i:sP';

    /**
     * Validation rules for an assisted reservation (RF-12). The barbershop is the one of the URL and the owner is the person of the session: the
     * body names neither. The person it is for is either `cliente_asistido`, one already registered, or the data of a new one.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** Identificador del servicio activo de la barbería. */
            'servicio' => ['required', 'uuid'],
            /** Identificador del barbero, el de un espacio de la consulta de disponibilidad. Aquí es obligatorio: el dueño elige el espacio. */
            'barbero' => ['required', 'uuid'],
            /** Inicio del espacio, fecha y hora ISO 8601 con desfase, por ejemplo `2030-03-05T10:00:00-06:00`. */
            'inicio' => ['required', 'date_format:'.self::START_FORMAT],
            /** Identificador de un cliente asistido ya registrado en esta barbería (de `GET /clientes-asistidos`). Si va, se ignoran los datos de `cliente_*`. */
            'cliente_asistido' => ['nullable', 'uuid'],
            /** Nombre del cliente nuevo. Obligatorio si no se manda `cliente_asistido`. */
            'cliente_nombre' => ['required_without:cliente_asistido', 'nullable', 'string', 'max:120'],
            /** Teléfono del cliente nuevo. Obligatorio si no se manda `cliente_asistido`. */
            'cliente_telefono' => ['required_without:cliente_asistido', 'nullable', 'string', 'max:'.Phone::MAX_LENGTH],
            /** Correo del cliente nuevo, opcional. Sin correo el cliente no recibe avisos ni recordatorios (CL-06). */
            'cliente_correo' => ['nullable', 'string', 'email', 'max:255'],
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

    /** Map the validated payload and the owner of the session to the use case input. */
    public function toCommand(): RegisterAssistedReservationCommand
    {
        $existing = $this->filled('cliente_asistido');

        return new RegisterAssistedReservationCommand(
            (string) $this->user()?->getAuthIdentifier(),
            $this->string('servicio')->toString(),
            $this->string('barbero')->toString(),
            new DateTimeImmutable($this->string('inicio')->toString())->setTimezone(new DateTimeZone((string) config('app.timezone'))),
            $existing ? $this->string('cliente_asistido')->toString() : null,
            $existing ? null : $this->string('cliente_nombre')->toString(),
            $existing ? null : $this->string('cliente_telefono')->toString(),
            $existing || ! $this->filled('cliente_correo') ? null : $this->string('cliente_correo')->toString(),
        );
    }
}
