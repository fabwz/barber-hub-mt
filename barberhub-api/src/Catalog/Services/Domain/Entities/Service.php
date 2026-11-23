<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Domain\Entities;

use Src\Catalog\Services\Domain\ValueObjects\ServiceDuration;
use Src\Catalog\Services\Domain\ValueObjects\ServiceId;
use Src\Catalog\Services\Domain\ValueObjects\ServiceName;
use Src\Catalog\Services\Domain\ValueObjects\ServicePrice;

final class Service
{
    /** Hold the full state of a service; the barbershop it belongs to is the context of the repository. */
    private function __construct(
        public readonly ServiceId $id,
        public private(set) ServiceName $name,
        public private(set) ServiceDuration $duration,
        public private(set) ServicePrice $price,
        public private(set) bool $active,
        public private(set) ?string $imagePath = null,
    ) {}

    /** Register a new service, active from the start (RF-03). */
    public static function create(ServiceId $id, ServiceName $name, ServiceDuration $duration, ServicePrice $price): self
    {
        return new self($id, $name, $duration, $price, true);
    }

    /** Rebuild a service from its stored state. */
    public static function reconstitute(ServiceId $id, ServiceName $name, ServiceDuration $duration, ServicePrice $price, bool $active, ?string $imagePath = null): self
    {
        return new self($id, $name, $duration, $price, $active, $imagePath);
    }

    /** Replace the editable details of the service. */
    public function revise(ServiceName $name, ServiceDuration $duration, ServicePrice $price): void
    {
        $this->name = $name;
        $this->duration = $duration;
        $this->price = $price;
    }

    /** Keep a new image of the service (RF-46, SV-07) and answer the path of the one it replaces, if there was one. */
    public function changeImage(string $path): ?string
    {
        $previous = $this->imagePath;
        $this->imagePath = $path;

        return $previous;
    }

    /** Offer the service again. */
    public function activate(): void
    {
        $this->active = true;
    }

    /** Stop offering the service without deleting it: it stays in the history (SV-04). */
    public function deactivate(): void
    {
        $this->active = false;
    }
}
