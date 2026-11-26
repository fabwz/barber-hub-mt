<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Identity\Users\Application\DTOs\ResetPasswordCommand;
use Src\Identity\Users\Infrastructure\Http\Rules\MeetsPasswordPolicy;

#[SchemaName('RestablecimientoDeContrasena')]
final class ResetPasswordRequest extends FormRequest
{
    /** Longest token the API reads: the link carries 64 characters, anything longer is not one of ours. */
    private const int TOKEN_MAX_LENGTH = 128;

    /**
     * Validation rules for the token of the link and the new password, which must meet SE-07.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:'.self::TOKEN_MAX_LENGTH],
            'contrasena_nueva' => ['bail', 'required', 'string', new MeetsPasswordPolicy],
        ];
    }

    /** Map the validated payload to the use case input. */
    public function toCommand(): ResetPasswordCommand
    {
        return new ResetPasswordCommand($this->string('token')->toString(), $this->string('contrasena_nueva')->toString());
    }
}
