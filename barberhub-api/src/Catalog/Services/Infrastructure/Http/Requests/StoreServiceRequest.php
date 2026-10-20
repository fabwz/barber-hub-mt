<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Catalog\Services\Application\DTOs\CreateServiceCommand;
use Src\Catalog\Services\Domain\ValueObjects\ServiceDuration;
use Src\Catalog\Services\Domain\ValueObjects\ServiceName;

#[SchemaName('CrearServicio')]
final class StoreServiceRequest extends FormRequest
{
    /**
     * Validation rules for a new service (SV-01).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:'.ServiceName::MAX_LENGTH],
            /** Entre 10 y 180 minutos, de 5 en 5. */
            'duracion_minutos' => ['required', 'integer', 'between:'.ServiceDuration::MIN_MINUTES.','.ServiceDuration::MAX_MINUTES, 'multiple_of:'.ServiceDuration::STEP_MINUTES],
            /** Colones enteros con el IVA de 13 % incluido, mayores que cero. */
            'precio' => ['required', 'integer', 'min:1'],
        ];
    }

    /** Map the validated payload to the use case input. */
    public function toCommand(): CreateServiceCommand
    {
        return new CreateServiceCommand(
            $this->string('nombre')->toString(),
            $this->integer('duracion_minutos'),
            $this->integer('precio'),
        );
    }
}
