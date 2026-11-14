<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Domain\ValueObjects;

use Src\Shared\Domain\Exceptions\InvalidValueException;

/** The services a voucher covers: at least one, none repeated (BO-01, BO-06). */
final readonly class CoveredServices
{
    /** @param  list<ServiceId>  $ids */
    private function __construct(public array $ids) {}

    /**
     * Build the set; an empty list is rejected and a repeated service counts once.
     *
     * @param  list<ServiceId>  $ids
     */
    public static function of(array $ids): self
    {
        $unique = [];

        foreach ($ids as $id) {
            $unique[$id->value] = $id;
        }

        if ($unique === []) {
            throw new InvalidValueException('vouchers.services_required');
        }

        return new self(array_values($unique));
    }

    /** Tell whether both sets hold the same services, whatever their order. */
    public function sameAs(self $other): bool
    {
        return count($this->ids) === count($other->ids)
            && array_all($this->ids, fn (ServiceId $id): bool => $other->covers($id));
    }

    /** Tell whether the voucher covers the service. */
    public function covers(ServiceId $service): bool
    {
        return array_any($this->ids, fn (ServiceId $id): bool => $id->equals($service));
    }
}
