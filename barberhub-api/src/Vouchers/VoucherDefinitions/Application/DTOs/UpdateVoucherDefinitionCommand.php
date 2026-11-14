<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application\DTOs;

final readonly class UpdateVoucherDefinitionCommand
{
    /**
     * Carry the fields to change; a null field keeps its current value.
     *
     * @param  list<string>|null  $serviceIds
     */
    public function __construct(
        public string $definitionId,
        public ?string $name = null,
        public ?int $sessionsCount = null,
        public ?int $price = null,
        public ?int $validityDays = null,
        public ?array $serviceIds = null,
        public ?bool $active = null,
    ) {}
}
