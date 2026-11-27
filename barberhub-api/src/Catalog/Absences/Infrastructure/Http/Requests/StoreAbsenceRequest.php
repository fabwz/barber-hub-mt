<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Catalog\Absences\Application\DTOs\RegisterAbsenceCommand;
use Src\Catalog\Absences\Domain\ValueObjects\AbsenceReason;

#[SchemaName('RegistrarAusencia')]
final class StoreAbsenceRequest extends FormRequest
{
    /**
     * Validation rules for the block of an agenda (RF-07). The shape of each value is checked here; that the range ends after it
     * starts, that it is not over and that the collaborator is active is decided by the use case.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** Identificador del colaborador cuya agenda se bloquea. */
            'colaborador' => ['required', 'uuid'],
            /** Primer día del bloqueo, AAAA-MM-DD, en hora de Costa Rica. */
            'fecha_desde' => ['required', 'date_format:Y-m-d'],
            /** Último día del bloqueo, AAAA-MM-DD, incluido. Para un solo día, la misma fecha. */
            'fecha_hasta' => ['required', 'date_format:Y-m-d'],
            /** Hora de inicio del primer día, HH:MM. Se envía junto con `hora_hasta` o no se envía: sin horas, el bloqueo cubre los días completos. */
            'hora_desde' => ['nullable', 'date_format:H:i', 'required_with:hora_hasta'],
            /** Hora de fin del último día, HH:MM. Se envía junto con `hora_desde`. */
            'hora_hasta' => ['nullable', 'date_format:H:i', 'required_with:hora_desde'],
            /** Motivo opcional, por ejemplo vacaciones o incapacidad. */
            'motivo' => ['nullable', 'string', 'max:'.AbsenceReason::MAX_LENGTH],
        ];
    }

    /** Map the validated payload to the use case input. */
    public function toCommand(): RegisterAbsenceCommand
    {
        return new RegisterAbsenceCommand(
            $this->string('colaborador')->toString(),
            $this->string('fecha_desde')->toString(),
            $this->string('fecha_hasta')->toString(),
            $this->filled('hora_desde') ? $this->string('hora_desde')->toString() : null,
            $this->filled('hora_hasta') ? $this->string('hora_hasta')->toString() : null,
            $this->filled('motivo') ? $this->string('motivo')->toString() : null,
        );
    }
}
