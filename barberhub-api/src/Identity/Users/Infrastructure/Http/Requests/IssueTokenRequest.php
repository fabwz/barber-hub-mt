<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Identity\Users\Application\DTOs\IssueTokenCommand;
use Src\Identity\Users\Domain\ValueObjects\Email;

#[SchemaName('Credenciales')]
final class IssueTokenRequest extends FormRequest
{
    public const int DEVICE_MAX_LENGTH = 100;

    /**
     * Validation rules for the credentials and the device that logs in.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'correo' => ['required', 'string', 'email:filter', 'max:'.Email::MAX_LENGTH],
            'contrasena' => ['required', 'string'],
            'dispositivo' => ['required', 'string', 'max:'.self::DEVICE_MAX_LENGTH],
        ];
    }

    /** Map the validated payload and the address of the request to the use case input. */
    public function toCommand(): IssueTokenCommand
    {
        return new IssueTokenCommand(
            $this->string('correo')->toString(),
            $this->string('contrasena')->toString(),
            $this->string('dispositivo')->toString(),
            (string) $this->ip(),
        );
    }
}
