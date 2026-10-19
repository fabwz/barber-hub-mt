<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Requests;

use DateTimeImmutable;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Identity\Users\Application\DTOs\RegisterCustomerCommand;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\Phone;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Identity\Users\Infrastructure\Http\Rules\MeetsPasswordPolicy;

#[SchemaName('RegistroDeCuenta')]
final class RegisterCustomerRequest extends FormRequest
{
    /**
     * Validation rules for the data of the account and its two separate acceptances (CL-02).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:'.UserName::MAX_LENGTH],
            'correo' => ['required', 'string', 'email:filter', 'max:'.Email::MAX_LENGTH],
            'telefono' => ['required', 'string', 'max:'.Phone::MAX_LENGTH],
            'contrasena' => ['bail', 'required', 'string', new MeetsPasswordPolicy],
            'acepta_terminos' => ['required', 'accepted'],
            'acepta_privacidad' => ['required', 'accepted'],
        ];
    }

    /** Map the validated payload to the use case input; both acceptances happen at the given instant. */
    public function toCommand(DateTimeImmutable $acceptedAt): RegisterCustomerCommand
    {
        return new RegisterCustomerCommand(
            $this->string('nombre')->toString(),
            $this->string('correo')->toString(),
            $this->string('telefono')->toString(),
            $this->string('contrasena')->toString(),
            $acceptedAt,
            $acceptedAt,
        );
    }
}
