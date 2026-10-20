<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Catalog\Barbershops\Application\DTOs\RegisterBarbershopCommand;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Shared\Domain\ValueObjects\Phone;

#[SchemaName('RegistroDeBarberia')]
final class RegisterBarbershopRequest extends FormRequest
{
    /**
     * Validation rules for the data of the barbershop (RF-01).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:'.BarbershopName::MAX_LENGTH],
            'ciudad' => ['required', 'string', 'max:'.City::MAX_LENGTH],
            'telefono' => ['required', 'string', 'max:'.Phone::MAX_LENGTH],
        ];
    }

    /** Map the validated payload to the use case input; the owner is the authenticated person. */
    public function toCommand(string $ownerId): RegisterBarbershopCommand
    {
        return new RegisterBarbershopCommand(
            $this->string('nombre')->toString(),
            $this->string('ciudad')->toString(),
            $this->string('telefono')->toString(),
            $ownerId,
        );
    }
}
