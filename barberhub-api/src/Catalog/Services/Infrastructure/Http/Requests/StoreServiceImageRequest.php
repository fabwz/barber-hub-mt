<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Src\Catalog\Services\Application\DTOs\ChangeServiceImageCommand;
use Src\Shared\Infrastructure\Http\Requests\ImageUpload;

#[SchemaName('ImagenDelServicio')]
final class StoreServiceImageRequest extends FormRequest
{
    /**
     * Validation rules for the image of a service (SV-06): the type is read from the content of the file, and it weighs up to 2 MB.
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
            'imagen' => ImageUpload::rules(),
        ];
    }

    /** Map the validated file to the use case input. */
    public function toCommand(): ChangeServiceImageCommand
    {
        $image = $this->validated('imagen');
        assert($image instanceof UploadedFile);

        return new ChangeServiceImageCommand((string) $this->route('servicio'), $image->getPathname(), ImageUpload::extensionOf($image));
    }
}
