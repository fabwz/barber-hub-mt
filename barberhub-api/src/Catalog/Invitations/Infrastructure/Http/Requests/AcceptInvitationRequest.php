<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Catalog\Invitations\Application\DTOs\AcceptInvitationCommand;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Identity\Users\Infrastructure\Http\Rules\MeetsPasswordPolicy;

#[SchemaName('AceptacionDeInvitacion')]
final class AcceptInvitationRequest extends FormRequest
{
    /** Longest token the API reads: the link carries 64 characters, anything longer is not one of ours. */
    private const int TOKEN_MAX_LENGTH = 128;

    /**
     * Validation rules for the token and the data of a new account. They are optional here because only the use case knows whether the email
     * of the link already has an account; when it has not, it asks for both (RF-06, SE-07).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            /** El `token` del enlace que llegó por correo. */
            'token' => ['required', 'string', 'max:'.self::TOKEN_MAX_LENGTH],
            /** Nombre de la persona; obligatorio si el correo no tiene cuenta, se ignora si la tiene. */
            'nombre' => ['nullable', 'string', 'max:'.UserName::MAX_LENGTH],
            /** Contraseña: al menos 8 caracteres, con mayúscula, minúscula, número y signo especial; obligatoria si el correo no tiene cuenta, se ignora si la tiene. */
            'contrasena' => ['bail', 'nullable', 'string', new MeetsPasswordPolicy],
        ];
    }

    /** Map the validated payload to the use case input. */
    public function toCommand(): AcceptInvitationCommand
    {
        return new AcceptInvitationCommand(
            $this->string('token')->toString(),
            $this->filled('nombre') ? $this->string('nombre')->toString() : null,
            $this->filled('contrasena') ? $this->string('contrasena')->toString() : null,
        );
    }
}
