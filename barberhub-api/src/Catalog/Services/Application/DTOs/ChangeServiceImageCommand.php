<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Application\DTOs;

final readonly class ChangeServiceImageCommand
{
    /** Carry the image to keep: the service, where the validated file is and the extension its content has. */
    public function __construct(public string $serviceId, public string $imagePath, public string $extension) {}
}
