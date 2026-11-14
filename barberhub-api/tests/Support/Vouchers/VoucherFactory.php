<?php

declare(strict_types=1);

namespace Tests\Support\Vouchers;

use DateTimeImmutable;
use DateTimeZone;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\CustomerMembershipId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\PurchasedVoucherId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\ReservationId;
use Src\Vouchers\Shared\Domain\ValueObjects\SessionCount;
use Src\Vouchers\Shared\Domain\ValueObjects\ValidityDays;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherName;
use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\CoveredServices;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\ServiceId;

/**
 * Builds the aggregates of the vouchers with the values of the example of the requirements: "Bono 5 cortes", 5 sessions for
 * ₡30.000 that last 90 days. It touches no database, so the tests of the domain use it too. Every argument can be replaced.
 */
final class VoucherFactory
{
    public const string BARBERSHOP = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0a01';

    public const string MEMBERSHIP = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0a02';

    public const string DEFINITION = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0a03';

    public const string VOUCHER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0a04';

    public const string CLASSIC_CUT = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0a05';

    public const string FADE = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0a06';

    public const string RESERVATION = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0a07';

    /** A fixed moment: Friday 1 March 2030, 8:00 in Costa Rica. */
    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica'));
    }

    /** A voucher definition on sale that covers the classic cut and the fade. */
    public static function definition(
        ?int $sessions = 5,
        ?int $price = 30000,
        ?int $validityDays = 90,
        ?CoveredServices $services = null,
        string $name = 'Bono 5 cortes',
        string $id = self::DEFINITION,
        string $barbershop = self::BARBERSHOP,
    ): VoucherDefinition {
        return VoucherDefinition::create(
            new VoucherDefinitionId($id),
            new BarbershopId($barbershop),
            VoucherName::of($name),
            SessionCount::of($sessions ?? 5),
            Money::of($price ?? 30000),
            ValidityDays::of($validityDays ?? 90),
            $services ?? CoveredServices::of([new ServiceId(self::CLASSIC_CUT), new ServiceId(self::FADE)]),
            self::now(),
        );
    }

    /** A voucher that was bought and whose payment is not confirmed yet. */
    public static function pending(
        int $sessions = 5,
        int $price = 30000,
        int $validityDays = 90,
        string $id = self::VOUCHER,
        string $barbershop = self::BARBERSHOP,
        string $membership = self::MEMBERSHIP,
        string $definition = self::DEFINITION,
    ): PurchasedVoucher {
        return PurchasedVoucher::purchase(
            new PurchasedVoucherId($id),
            new BarbershopId($barbershop),
            new CustomerMembershipId($membership),
            new VoucherDefinitionId($definition),
            VoucherName::of('Bono 5 cortes'),
            Money::of($price),
            SessionCount::of($sessions),
            ValidityDays::of($validityDays),
            self::now(),
        );
    }

    /** A voucher whose payment was confirmed at the given moment (the fixed one by default): all its sessions, valid for its days. */
    public static function active(
        int $sessions = 5,
        int $validityDays = 90,
        ?DateTimeImmutable $activatedAt = null,
        string $id = self::VOUCHER,
        string $barbershop = self::BARBERSHOP,
        string $membership = self::MEMBERSHIP,
        string $definition = self::DEFINITION,
    ): PurchasedVoucher {
        $voucher = self::pending($sessions, 30000, $validityDays, $id, $barbershop, $membership, $definition);
        $voucher->activate($activatedAt ?? self::now());

        return $voucher;
    }

    /** The reservation of the example, or another one by its identifier. */
    public static function reservation(string $id = self::RESERVATION): ReservationId
    {
        return new ReservationId($id);
    }
}
