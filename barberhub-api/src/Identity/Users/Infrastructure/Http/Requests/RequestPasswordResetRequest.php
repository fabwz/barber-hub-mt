<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Identity\Users\Application\DTOs\RequestPasswordResetCommand;
use Src\Identity\Users\Domain\ValueObjects\Email;

#[SchemaName('SolicitudDeRestablecimiento')]
final class RequestPasswordResetRequest extends FormRequest
{
    /**
     * Validation rules for the email that asks for the link.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'correo' => ['required', 'string', 'email:filter', 'max:'.Email::MAX_LENGTH],
        ];
    }

    /** Map the validated payload and the address of the request to the use case input. */
    public function toCommand(): RequestPasswordResetCommand
    {
        return new RequestPasswordResetCommand($this->string('correo')->toString(), (string) $this->ip());
    }
}
