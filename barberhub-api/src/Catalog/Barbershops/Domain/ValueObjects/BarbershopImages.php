<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\ValueObjects;

/** The stored paths of the logo and the banner of a barbershop; null when it has none (RF-46). */
final readonly class BarbershopImages
{
    /** Hold the paths. */
    private function __construct(public ?string $logo, public ?string $banner) {}

    /** A barbershop that has uploaded nothing. */
    public static function none(): self
    {
        return new self(null, null);
    }

    /** Build the images from their stored paths. */
    public static function of(?string $logo, ?string $banner): self
    {
        return new self($logo, $banner);
    }

    /** The path of one of the images. */
    public function pathOf(BarbershopImageKind $kind): ?string
    {
        return match ($kind) {
            BarbershopImageKind::Logo => $this->logo,
            BarbershopImageKind::Banner => $this->banner,
        };
    }

    /** The same images with the path of one of them replaced. */
    public function replacing(BarbershopImageKind $kind, string $path): self
    {
        return match ($kind) {
            BarbershopImageKind::Logo => new self($path, $this->banner),
            BarbershopImageKind::Banner => new self($this->logo, $path),
        };
    }
}
