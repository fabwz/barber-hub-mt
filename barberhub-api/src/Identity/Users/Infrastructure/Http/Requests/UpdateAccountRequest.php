<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Identity\Users\Application\DTOs\UpdateAccountCommand;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Shared\Domain\ValueObjects\Phone;

#[SchemaName('CorreccionDeCuenta')]
final class UpdateAccountRequest extends FormRequest
{
    /**
     * Validation rules for the name, email and phone; nothing else is read from the body.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:'.UserName::MAX_LENGTH],
            'correo' => ['required', 'string', 'email:filter', 'max:'.Email::MAX_LENGTH],
            'telefono' => ['required', 'string', 'max:'.Phone::MAX_LENGTH],
        ];
    }

    /** Map the validated payload to the use case input. */
    public function toCommand(string $userId): UpdateAccountCommand
    {
        return new UpdateAccountCommand(
            $userId,
            $this->string('nombre')->toString(),
            $this->string('correo')->toString(),
            $this->string('telefono')->toString(),
        );
    }
}
