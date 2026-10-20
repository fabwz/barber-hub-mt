<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;

#[SchemaName('CambiarEstadoDeColaborador')]
final class UpdateCollaboratorRequest extends FormRequest
{
    /**
     * Validation rules for activating or deactivating a collaborator (CO-03).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** `true` para que reciba reservas; `false` para dejar de asignarle. */
            'activo' => ['required', 'boolean'],
        ];
    }
}
