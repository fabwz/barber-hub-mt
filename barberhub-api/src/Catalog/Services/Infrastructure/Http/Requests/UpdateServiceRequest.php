<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Catalog\Services\Application\DTOs\UpdateServiceCommand;
use Src\Catalog\Services\Domain\ValueObjects\ServiceDuration;
use Src\Catalog\Services\Domain\ValueObjects\ServiceName;

#[SchemaName('ActualizarServicio')]
final class UpdateServiceRequest extends FormRequest
{
    /**
     * Validation rules for a partial update of a service (SV-01, SV-04).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:'.ServiceName::MAX_LENGTH],
            /** Entre 10 y 180 minutos, de 5 en 5. */
            'duracion_minutos' => ['sometimes', 'required', 'integer', 'between:'.ServiceDuration::MIN_MINUTES.','.ServiceDuration::MAX_MINUTES, 'multiple_of:'.ServiceDuration::STEP_MINUTES],
            /** Colones enteros con el IVA de 13 % incluido, mayores que cero. */
            'precio' => ['sometimes', 'required', 'integer', 'min:1'],
            /** `false` inactiva el servicio y `true` lo vuelve a activar; un servicio nunca se borra. */
            'activo' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /** Map the validated payload to the use case input; a field that did not come keeps its current value. */
    public function toCommand(): UpdateServiceCommand
    {
        $data = $this->validated();

        return new UpdateServiceCommand(
            (string) $this->route('servicio'),
            isset($data['nombre']) ? (string) $data['nombre'] : null,
            isset($data['duracion_minutos']) ? (int) $data['duracion_minutos'] : null,
            isset($data['precio']) ? (int) $data['precio'] : null,
            isset($data['activo']) ? (bool) $data['activo'] : null,
        );
    }
}
