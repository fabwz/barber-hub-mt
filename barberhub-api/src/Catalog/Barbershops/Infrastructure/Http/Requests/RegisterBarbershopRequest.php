<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Catalog\Barbershops\Application\DTOs\RegisterBarbershopCommand;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Shared\Domain\ValueObjects\Phone;

#[SchemaName('RegistroDeBarberia')]
final class RegisterBarbershopRequest extends FormRequest
{
    /**
     * Validation rules for the data of the barbershop and of its owner (RF-01).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:'.BarbershopName::MAX_LENGTH],
            'ciudad' => ['required', 'string', 'max:'.City::MAX_LENGTH],
            'telefono' => ['required', 'string', 'max:'.Phone::MAX_LENGTH],
            'dueno' => ['required', 'array'],
            'dueno.nombre' => ['required', 'string', 'max:'.UserName::MAX_LENGTH],
            'dueno.correo' => ['required', 'string', 'email:filter', 'max:'.Email::MAX_LENGTH],
            'dueno.telefono' => ['required', 'string', 'max:'.Phone::MAX_LENGTH],
        ];
    }

    /** Map the validated payload to the use case input; the author is the platform operator who is authenticated. */
    public function toCommand(string $operatorId): RegisterBarbershopCommand
    {
        return new RegisterBarbershopCommand(
            $this->string('nombre')->toString(),
            $this->string('ciudad')->toString(),
            $this->string('telefono')->toString(),
            $this->string('dueno.nombre')->toString(),
            $this->string('dueno.correo')->toString(),
            $this->string('dueno.telefono')->toString(),
            $operatorId,
        );
    }
}
