<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Catalog\Barbershops\Application\DTOs\UpdateBarbershopCommand;
use Src\Catalog\Barbershops\Domain\ValueObjects\Address;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Catalog\Barbershops\Domain\ValueObjects\ContactEmail;
use Src\Catalog\Barbershops\Domain\ValueObjects\SocialLink;
use Src\Catalog\Barbershops\Domain\ValueObjects\SocialNetwork;
use Src\Shared\Domain\ValueObjects\Phone;

#[SchemaName('ActualizarBarberia')]
final class UpdateBarbershopRequest extends FormRequest
{
    /**
     * Validation rules for a partial update of the data of the barbershop (RF-01). The form of each field is checked here; the
     * domain of each social link belongs to the link itself and answers 422 as well, under `redes.<red>`.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /**
             * Nombre de la barbería. Cambiarlo no cambia el enlace público.
             *
             * @example Barbería Pura Navaja
             */
            'nombre' => ['sometimes', 'required', 'string', 'max:'.BarbershopName::MAX_LENGTH],
            /**
             * Ciudad.
             *
             * @example Ciudad Quesada
             */
            'ciudad' => ['sometimes', 'required', 'string', 'max:'.City::MAX_LENGTH],
            /**
             * Teléfono de contacto.
             *
             * @example 2460-0000
             */
            'telefono' => ['sometimes', 'required', 'string', 'max:'.Phone::MAX_LENGTH],
            /**
             * Dirección en una sola línea, de hasta 200 caracteres. `null` o vacío la borra.
             *
             * @example 100 m norte de la iglesia, Ciudad Quesada
             */
            'direccion' => ['sometimes', 'nullable', 'string', 'max:'.Address::MAX_LENGTH, 'regex:/^[^\x00-\x1F\x7F]*$/'],
            /**
             * Correo del negocio, de hasta 150 caracteres; no es el correo de la cuenta. `null` o vacío lo borra.
             *
             * @example contacto@puranavaja.test
             */
            'correo_contacto' => ['sometimes', 'nullable', 'string', 'email', 'max:'.ContactEmail::MAX_LENGTH],
            /** Redes sociales; solo las que vienen cambian. */
            'redes' => ['sometimes', 'nullable', 'array:'.implode(',', array_column(SocialNetwork::cases(), 'value'))],
            /**
             * Enlace `https://` a `wa.me` o `api.whatsapp.com`, de hasta 300 caracteres. `null` o vacío lo borra.
             *
             * @example https://wa.me/50600000000
             */
            'redes.whatsapp' => ['nullable', 'string', 'max:'.SocialLink::MAX_LENGTH],
            /**
             * Enlace `https://` a `instagram.com`, de hasta 300 caracteres. `null` o vacío lo borra.
             *
             * @example https://instagram.com/puranavaja
             */
            'redes.instagram' => ['nullable', 'string', 'max:'.SocialLink::MAX_LENGTH],
            /**
             * Enlace `https://` a `facebook.com` o `fb.com`, de hasta 300 caracteres. `null` o vacío lo borra.
             *
             * @example https://facebook.com/puranavaja
             */
            'redes.facebook' => ['nullable', 'string', 'max:'.SocialLink::MAX_LENGTH],
            /**
             * Enlace `https://` a `tiktok.com`, de hasta 300 caracteres. `null` o vacío lo borra.
             *
             * @example https://www.tiktok.com/@puranavaja
             */
            'redes.tiktok' => ['nullable', 'string', 'max:'.SocialLink::MAX_LENGTH],
        ];
    }

    /** Map the validated payload to the use case input; a field that did not come keeps its current value. */
    public function toCommand(): UpdateBarbershopCommand
    {
        $data = $this->validated();
        $profile = [];

        foreach (['direccion' => 'address', 'correo_contacto' => 'contact_email'] as $attribute => $key) {
            if (array_key_exists($attribute, $data)) {
                $profile[$key] = $data[$attribute] === null ? null : (string) $data[$attribute];
            }
        }

        $networks = is_array($data['redes'] ?? null) ? $data['redes'] : [];
        foreach (SocialNetwork::cases() as $network) {
            if (array_key_exists($network->value, $networks)) {
                $profile[$network->value] = $networks[$network->value] === null ? null : (string) $networks[$network->value];
            }
        }

        return new UpdateBarbershopCommand(
            isset($data['nombre']) ? (string) $data['nombre'] : null,
            isset($data['ciudad']) ? (string) $data['ciudad'] : null,
            isset($data['telefono']) ? (string) $data['telefono'] : null,
            $profile,
        );
    }
}
