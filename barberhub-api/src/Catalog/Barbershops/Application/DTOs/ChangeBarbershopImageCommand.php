<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\DTOs;

final readonly class ChangeBarbershopImageCommand
{
    /** Carry the image to keep: which one (`logo` or `banner`), where the validated file is and the extension its content has. */
    public function __construct(public string $kind, public string $imagePath, public string $extension) {}
}
