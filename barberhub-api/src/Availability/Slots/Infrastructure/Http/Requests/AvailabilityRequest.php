<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\ProhibitedIf;

final class AvailabilityRequest extends FormRequest
{
    private const string DATE_FORMAT = 'Y-m-d';

    /**
     * Validation rules for the query of the availability (RF-10).
     *
     * @return array<string, list<string|ProhibitedIf>>
     */
    public function rules(): array
    {
        return [
            /** Identificador del servicio activo de la barbería. */
            'servicio' => ['required', 'uuid'],
            /** Identificador del barbero. Si se omite, trae los espacios de todos los que ofrecen el servicio. No se combina con `cualquiera`. */
            'barbero' => ['nullable', 'uuid', Rule::prohibitedIf(fn (): bool => $this->boolean('cualquiera'))],
            /** Verdadero para «cualquiera disponible»: un espacio por hora, con el barbero que el sistema asigna (el de menos reservas ese día y, si empatan, el de mayor antigüedad de alta). Por omisión es falso. */
            'cualquiera' => ['sometimes', 'boolean'],
            /** Día de la consulta, AAAA-MM-DD, en hora de Costa Rica. */
            'fecha' => ['required', 'date_format:'.self::DATE_FORMAT],
        ];
    }

    /** A query string carries `true` and `false` as text; make them the booleans the rule understands. */
    protected function prepareForValidation(): void
    {
        $any = $this->query('cualquiera');

        if (is_string($any) && in_array(strtolower($any), ['true', 'false'], true)) {
            $this->merge(['cualquiera' => strtolower($any) === 'true']);
        }
    }

    /** The service asked for. */
    public function serviceId(): string
    {
        return $this->string('servicio')->toString();
    }

    /** The collaborator asked for, or none for all who offer the service. */
    public function collaboratorId(): ?string
    {
        return $this->filled('barbero') ? $this->string('barbero')->toString() : null;
    }

    /** Whether the customer accepts any available collaborator (DI-04). */
    public function anyAvailable(): bool
    {
        return $this->boolean('cualquiera');
    }

    /** The day asked for, written as YYYY-MM-DD. */
    public function day(): string
    {
        return $this->string('fecha')->toString();
    }
}
