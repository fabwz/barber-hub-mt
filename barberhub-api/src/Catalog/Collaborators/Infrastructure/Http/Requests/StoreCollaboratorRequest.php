<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Catalog\Collaborators\Application\DTOs\CreateCollaboratorCommand;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Identity\Users\Infrastructure\Http\Rules\MeetsPasswordPolicy;

#[SchemaName('CrearColaborador')]
final class StoreCollaboratorRequest extends FormRequest
{
    /**
     * Validation rules for the account of a new collaborator and its temporary password (RF-05, UP-04, SE-07).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            /** Nombre de la persona. */
            'nombre' => ['required', 'string', 'max:'.UserName::MAX_LENGTH],
            /** Correo de la cuenta; no puede tener ya una cuenta. */
            'correo' => ['required', 'string', 'email:filter', 'max:'.Email::MAX_LENGTH],
            /** Contraseña temporal: al menos 8 caracteres, con mayúscula, minúscula, número y signo especial. Nunca se devuelve. */
            'contrasena_temporal' => ['bail', 'required', 'string', new MeetsPasswordPolicy],
        ];
    }

    /** Map the validated payload to the use case input. */
    public function toCommand(): CreateCollaboratorCommand
    {
        return new CreateCollaboratorCommand(
            $this->string('nombre')->toString(),
            $this->string('correo')->toString(),
            $this->string('contrasena_temporal')->toString(),
        );
    }
}
