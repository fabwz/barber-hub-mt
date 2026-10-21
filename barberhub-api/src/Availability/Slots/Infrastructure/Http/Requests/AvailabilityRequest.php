<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AvailabilityRequest extends FormRequest
{
    private const string DATE_FORMAT = 'Y-m-d';

    /**
     * Validation rules for the query of the availability (RF-10).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** Identificador del servicio activo de la barbería. */
            'servicio' => ['required', 'uuid'],
            /** Identificador del barbero. Si se omite, trae los espacios de todos los que ofrecen el servicio. */
            'barbero' => ['nullable', 'uuid'],
            /** Día de la consulta, AAAA-MM-DD, en hora de Costa Rica. */
            'fecha' => ['required', 'date_format:'.self::DATE_FORMAT],
        ];
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

    /** The day asked for, written as YYYY-MM-DD. */
    public function day(): string
    {
        return $this->string('fecha')->toString();
    }
}
