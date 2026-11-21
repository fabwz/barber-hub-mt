<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Identity\Users\Application\DTOs\ChangePasswordCommand;
use Src\Identity\Users\Infrastructure\Http\Rules\MeetsPasswordPolicy;

#[SchemaName('CambioDeContrasena')]
final class ChangePasswordRequest extends FormRequest
{
    /**
     * Validation rules for the current password and the new one, which must meet SE-07.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'contrasena_actual' => ['required', 'string'],
            'contrasena_nueva' => ['bail', 'required', 'string', new MeetsPasswordPolicy],
        ];
    }

    /** Map the validated payload to the use case input. */
    public function toCommand(string $userId, string $currentTokenId): ChangePasswordCommand
    {
        return new ChangePasswordCommand(
            $userId,
            $this->string('contrasena_actual')->toString(),
            $this->string('contrasena_nueva')->toString(),
            $currentTokenId,
        );
    }
}
