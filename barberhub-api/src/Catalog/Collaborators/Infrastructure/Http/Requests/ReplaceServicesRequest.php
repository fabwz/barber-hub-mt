<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Catalog\Collaborators\Application\DTOs\ReplaceServicesCommand;

#[SchemaName('CambiarServiciosDeColaborador')]
final class ReplaceServicesRequest extends FormRequest
{
    /**
     * Validation rules for the services a collaborator offers (CO-01).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** Identificadores de los servicios de la barbería que ofrece; una lista vacía le quita todos. */
            'servicios' => ['present', 'array'],
            'servicios.*' => ['required', 'uuid'],
        ];
    }

    /** Map the validated payload to the use case input; identifiers are stored in lowercase. */
    public function toCommand(string $collaboratorId): ReplaceServicesCommand
    {
        return new ReplaceServicesCommand(
            $collaboratorId,
            array_map(fn (mixed $id): string => strtolower((string) $id), array_values($this->array('servicios'))),
        );
    }
}
