<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopImages;
use Src\Shared\Infrastructure\Storage\MediaUrls;

/**
 * @property-read BarbershopImages $resource
 */
#[SchemaName('ImagenesDeLaBarberia')]
final class BarbershopImagesResource extends JsonResource
{
    /** Wrap the images of a barbershop. */
    public function __construct(private readonly BarbershopImages $images)
    {
        parent::__construct($images);
    }

    /**
     * Expose the address of the logo and of the banner, `null` where the barbershop has none.
     *
     * @return array{logo: string|null, banner: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            /** Dirección del logo, relativa al servidor (`/api/v1/archivos/...`), o `null` si no tiene. */
            'logo' => MediaUrls::of($this->images->logo),
            /** Dirección del banner, relativa al servidor (`/api/v1/archivos/...`), o `null` si no tiene. */
            'banner' => MediaUrls::of($this->images->banner),
        ];
    }
}
