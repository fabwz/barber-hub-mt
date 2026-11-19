<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Waitlist\Entries\Application\DTOs\JoinWaitlistCommand;

#[SchemaName('AnotarseEnFila')]
final class JoinWaitlistRequest extends FormRequest
{
    /**
     * Validation rules for joining the waitlist (RF-30). The barbershop is the one of the URL: the body never names it, nor the
     * customer, who is the person of the session.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** Identificador del servicio activo de la barbería. */
            'servicio' => ['required', 'uuid'],
            /** Día que busca, fecha de Costa Rica `AAAA-MM-DD`, de hoy en adelante. */
            'fecha' => ['required', 'date_format:Y-m-d'],
            /** Identificador del barbero; nulo u omitido significa «cualquiera». */
            'barbero' => ['nullable', 'uuid'],
        ];
    }

    /** Map the validated payload and the person of the session to the use case input. */
    public function toCommand(): JoinWaitlistCommand
    {
        $collaborator = $this->input('barbero');

        return new JoinWaitlistCommand(
            (string) $this->user()?->getAuthIdentifier(),
            $this->string('servicio')->toString(),
            is_string($collaborator) ? $collaborator : null,
            $this->string('fecha')->toString(),
        );
    }
}
