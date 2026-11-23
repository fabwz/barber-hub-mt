<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Src\Catalog\Barbershops\Application\DTOs\ChangeBarbershopImageCommand;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopImageKind;
use Src\Shared\Infrastructure\Http\Requests\ImageUpload;

#[SchemaName('LogoDeLaBarberia')]
final class StoreLogoRequest extends FormRequest
{
    /**
     * Validation rules for the logo (SV-06): the type is read from the content of the file, and it weighs up to 2 MB.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /**
             * Imagen JPG, PNG o WebP de hasta 2 MB. El tipo se lee del contenido del archivo, no de su nombre.
             *
             * @format binary
             */
            'logo' => ImageUpload::rules(),
        ];
    }

    /** Map the validated file to the use case input. */
    public function toCommand(): ChangeBarbershopImageCommand
    {
        $logo = $this->validated('logo');
        assert($logo instanceof UploadedFile);

        return new ChangeBarbershopImageCommand(BarbershopImageKind::Logo->value, $logo->getPathname(), ImageUpload::extensionOf($logo));
    }
}
