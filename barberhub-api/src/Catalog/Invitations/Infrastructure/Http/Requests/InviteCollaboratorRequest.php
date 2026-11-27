<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Catalog\Invitations\Application\DTOs\InviteCollaboratorCommand;
use Src\Identity\Users\Domain\ValueObjects\Email;

#[SchemaName('InvitarColaborador')]
final class InviteCollaboratorRequest extends FormRequest
{
    /**
     * Validation rules for the email invited and the services the collaborator will offer (RF-06, CO-01).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** Correo de la persona invitada; recibe el enlace para crear su contraseña. */
            'correo' => ['required', 'string', 'email:filter', 'max:'.Email::MAX_LENGTH],
            /** Identificadores de los servicios de la barbería que ofrecerá; puede estar vacía. */
            'servicios' => ['present', 'array'],
            'servicios.*' => ['required', 'uuid'],
        ];
    }

    /** Map the validated payload to the use case input; identifiers are stored in lowercase. */
    public function toCommand(string $invitedByUserId): InviteCollaboratorCommand
    {
        return new InviteCollaboratorCommand(
            $invitedByUserId,
            $this->string('correo')->toString(),
            array_map(fn (mixed $id): string => strtolower((string) $id), array_values($this->array('servicios'))),
        );
    }
}
