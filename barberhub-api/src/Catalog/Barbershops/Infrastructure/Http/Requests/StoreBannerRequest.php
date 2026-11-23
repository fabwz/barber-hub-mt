<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Src\Catalog\Barbershops\Application\DTOs\ChangeBarbershopImageCommand;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopImageKind;
use Src\Shared\Infrastructure\Http\Requests\ImageUpload;

#[SchemaName('BannerDeLaBarberia')]
final class StoreBannerRequest extends FormRequest
{
    /** Smallest banner, in pixels (SV-06). */
    public const int MIN_WIDTH = 1200;

    public const int MIN_HEIGHT = 400;

    /**
     * Validation rules for the banner (SV-06): the type is read from the content of the file, it weighs up to 2 MB and measures at least 1.200 × 400 px.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /**
             * Imagen JPG, PNG o WebP de hasta 2 MB y de al menos 1.200 × 400 píxeles. El tipo se lee del contenido del archivo, no de su nombre.
             *
             * @format binary
             */
            'banner' => ImageUpload::rules(self::MIN_WIDTH, self::MIN_HEIGHT),
        ];
    }

    /** Map the validated file to the use case input. */
    public function toCommand(): ChangeBarbershopImageCommand
    {
        $banner = $this->validated('banner');
        assert($banner instanceof UploadedFile);

        return new ChangeBarbershopImageCommand(BarbershopImageKind::Banner->value, $banner->getPathname(), ImageUpload::extensionOf($banner));
    }
}
