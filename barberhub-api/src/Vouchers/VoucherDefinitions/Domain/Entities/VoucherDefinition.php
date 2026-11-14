<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Domain\Entities;

use DateTimeImmutable;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Vouchers\Shared\Domain\ValueObjects\SessionCount;
use Src\Vouchers\Shared\Domain\ValueObjects\ValidityDays;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherName;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\CoveredServices;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\ServiceId;

/**
 * The voucher a barbershop sells (BO-01): name, sessions, price, validity in days and the services it covers. It is a catalog
 * entry, not an aggregate: changing or withdrawing it never reaches the vouchers already purchased, which keep their own copy.
 */
final class VoucherDefinition
{
    /** Hold the full state of a definition. */
    private function __construct(
        public readonly VoucherDefinitionId $id,
        public readonly BarbershopId $barbershopId,
        public private(set) VoucherName $name,
        public private(set) SessionCount $sessionsCount,
        public private(set) Money $price,
        public private(set) ValidityDays $validityDays,
        public private(set) CoveredServices $services,
        public private(set) bool $isActive,
        public readonly DateTimeImmutable $createdAt,
        public private(set) DateTimeImmutable $updatedAt,
    ) {}

    /** Create a definition on sale. The price is in whole colones, VAT included, and above zero. */
    public static function create(
        VoucherDefinitionId $id,
        BarbershopId $barbershopId,
        VoucherName $name,
        SessionCount $sessionsCount,
        Money $price,
        ValidityDays $validityDays,
        CoveredServices $services,
        DateTimeImmutable $at,
    ): self {
        self::assertPrice($price);

        return new self($id, $barbershopId, $name, $sessionsCount, $price, $validityDays, $services, true, $at, $at);
    }

    /** Rebuild a definition from its stored state. */
    public static function reconstitute(
        VoucherDefinitionId $id,
        BarbershopId $barbershopId,
        VoucherName $name,
        SessionCount $sessionsCount,
        Money $price,
        ValidityDays $validityDays,
        CoveredServices $services,
        bool $isActive,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $barbershopId, $name, $sessionsCount, $price, $validityDays, $services, $isActive, $createdAt, $updatedAt);
    }

    /** Change the name and the price. Vouchers already purchased keep what they were sold for (RF-26). */
    public function revise(VoucherName $name, Money $price, DateTimeImmutable $at): void
    {
        self::assertPrice($price);

        if ($name->equals($this->name) && $price->equals($this->price)) {
            return;
        }

        $this->name = $name;
        $this->price = $price;
        $this->updatedAt = $at;
    }

    /**
     * Change what the voucher gives: the sessions, the validity and the services it covers. The caller decides whether it may,
     * because it depends on whether the voucher was sold, which the definition does not know.
     */
    public function reconfigure(SessionCount $sessionsCount, ValidityDays $validityDays, CoveredServices $services, DateTimeImmutable $at): void
    {
        if ($sessionsCount->equals($this->sessionsCount) && $validityDays->equals($this->validityDays) && $services->sameAs($this->services)) {
            return;
        }

        $this->sessionsCount = $sessionsCount;
        $this->validityDays = $validityDays;
        $this->services = $services;
        $this->updatedAt = $at;
    }

    /** Put it back on sale. Reactivating one that is on sale changes nothing. */
    public function reactivate(DateTimeImmutable $at): void
    {
        if ($this->isActive) {
            return;
        }

        $this->isActive = true;
        $this->updatedAt = $at;
    }

    /** Take it off sale; vouchers already purchased keep their balance (RF-26). Withdrawing twice changes nothing. */
    public function withdraw(DateTimeImmutable $at): void
    {
        if (! $this->isActive) {
            return;
        }

        $this->isActive = false;
        $this->updatedAt = $at;
    }

    /** Tell whether the voucher covers a service (BO-06). */
    public function covers(ServiceId $service): bool
    {
        return $this->services->covers($service);
    }

    /** A voucher is sold at a price above zero. */
    private static function assertPrice(Money $price): void
    {
        if ($price->colones <= 0) {
            throw new InvalidValueException('vouchers.price_invalid');
        }
    }
}
