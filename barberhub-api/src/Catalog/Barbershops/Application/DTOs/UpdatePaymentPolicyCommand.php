<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\DTOs;

final readonly class UpdatePaymentPolicyCommand
{
    /** Carry the changes to the payment policy; a null field keeps its current value. */
    public function __construct(
        public ?string $mode,
        public ?int $depositPercentage,
        public ?int $cancellationDeadlineHours,
        public ?string $gateway,
    ) {}
}
