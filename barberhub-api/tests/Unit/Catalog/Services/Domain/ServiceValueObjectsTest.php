<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Services\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Catalog\Services\Domain\ValueObjects\ServiceDuration;
use Src\Catalog\Services\Domain\ValueObjects\ServiceId;
use Src\Catalog\Services\Domain\ValueObjects\ServiceName;
use Src\Catalog\Services\Domain\ValueObjects\ServicePrice;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final class ServiceValueObjectsTest extends TestCase
{
    /**
     * Durations the rule accepts: the limits and the steps of 5 (SV-01).
     *
     * @return array<string, array{int}>
     */
    public static function validDurations(): array
    {
        return ['minimum' => [10], 'half hour' => [30], 'odd step' => [55], 'maximum' => [180]];
    }

    /** A duration from 10 to 180 minutes in steps of 5 is kept as minutes of the shared duration. */
    #[DataProvider('validDurations')]
    public function test_duration_accepts_the_allowed_values(int $minutes): void
    {
        $duration = new ServiceDuration($minutes);

        $this->assertSame($minutes, $duration->minutes());
        $this->assertSame($minutes, $duration->duration->minutes);
    }

    /**
     * Durations the rule refuses: below, above and between steps.
     *
     * @return array<string, array{int}>
     */
    public static function invalidDurations(): array
    {
        return [
            'seven minutes' => [7],
            'nine minutes' => [9],
            'between steps' => [12],
            'zero' => [0],
            'negative' => [-30],
            'one over the maximum' => [181],
            'between steps over the maximum' => [185],
            'two hundred minutes' => [200],
        ];
    }

    /** Any other duration is refused with the message that names the field and its limits (SV-01). */
    #[DataProvider('invalidDurations')]
    public function test_duration_rejects_the_other_values(int $minutes): void
    {
        try {
            new ServiceDuration($minutes);
            $this->fail("A duration of {$minutes} minutes was accepted.");
        } catch (InvalidValueException $exception) {
            $this->assertSame('services.duration_invalid', $exception->translationKey());
            $this->assertSame(['min' => 10, 'max' => 180, 'step' => 5], $exception->parameters());
        }
    }

    /**
     * Prices that are not whole colones above zero.
     *
     * @return array<string, array{int}>
     */
    public static function invalidPrices(): array
    {
        return ['zero' => [0], 'negative' => [-7000]];
    }

    /** A price of ₡0 or less is refused with the message that names the field (SV-01). */
    #[DataProvider('invalidPrices')]
    public function test_price_rejects_zero_and_negative_amounts(int $colones): void
    {
        try {
            new ServicePrice($colones);
            $this->fail("A price of {$colones} colones was accepted.");
        } catch (InvalidValueException $exception) {
            $this->assertSame('services.price_invalid', $exception->translationKey());
        }
    }

    /** The price is kept as whole colones of the shared money. */
    public function test_price_keeps_whole_colones(): void
    {
        $price = new ServicePrice(7000);

        $this->assertSame(7000, $price->colones());
        $this->assertSame(7000, $price->money->colones);
        $this->assertSame(1, new ServicePrice(1)->colones());
    }

    /**
     * Prices with the tax and the base they split into: the tax is price × 13 / 113 rounded to the nearest colon.
     *
     * @return array<string, array{int, int, int}>
     */
    public static function breakdowns(): array
    {
        return [
            'one colon, the tax rounds down to zero' => [1, 1, 0],
            'ten colones, 1.15 rounds down' => [10, 9, 1],
            'fifty colones, 5.75 rounds up' => [50, 44, 6],
            'one hundred colones, 11.50 rounds up' => [100, 88, 12],
            'one hundred and thirteen, exact' => [113, 100, 13],
            'classic cut, 805.31 rounds down' => [7000, 6195, 805],
            'classic cut after the raise, 862.83 rounds up' => [7500, 6637, 863],
            'ten thousand, 1150.44 rounds down' => [10000, 8850, 1150],
        ];
    }

    /** The VAT of 13 % is inside the price, and base plus tax is always exactly the price (SV-02, RL-02). */
    #[DataProvider('breakdowns')]
    public function test_price_splits_into_base_and_tax(int $price, int $base, int $tax): void
    {
        $servicePrice = new ServicePrice($price);

        $this->assertSame($tax, $servicePrice->tax()->colones);
        $this->assertSame($base, $servicePrice->base()->colones);
        $this->assertSame($price, $servicePrice->base()->plus($servicePrice->tax())->colones);
    }

    /** Whatever the price, the two parts add up to it and the tax is the nearest colon to the exact value. */
    public function test_breakdown_adds_up_and_rounds_to_the_nearest_colon(): void
    {
        for ($price = 1; $price <= 20000; $price++) {
            $servicePrice = new ServicePrice($price);
            $tax = $servicePrice->tax()->colones;

            $this->assertSame($price, $servicePrice->base()->colones + $tax);
            $this->assertLessThanOrEqual(0.5, abs($tax - $price * 13 / 113), "Tax of {$price}");
        }
    }

    /** The name is trimmed and limited to 120 characters. */
    public function test_name_is_trimmed_and_limited(): void
    {
        $this->assertSame('Corte clásico', new ServiceName('  Corte clásico ')->value);
        $this->assertSame(120, mb_strlen(new ServiceName(str_repeat('ñ', 120))->value));
    }

    /**
     * Names that break the invariant.
     *
     * @return array<string, array{string}>
     */
    public static function invalidNames(): array
    {
        return ['empty' => [''], 'blank' => ['   '], 'too long' => [str_repeat('a', 121)]];
    }

    /** A blank or oversized name is rejected with a translatable error. */
    #[DataProvider('invalidNames')]
    public function test_name_rejects_invalid_values(string $name): void
    {
        try {
            new ServiceName($name);
            $this->fail('An invalid service name was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame('services.name_invalid', $exception->translationKey());
            $this->assertSame(['max' => 120], $exception->parameters());
        }
    }

    /** The identifier only accepts a canonical UUID. */
    public function test_id_rejects_a_malformed_value(): void
    {
        $this->expectException(InvalidValueException::class);

        new ServiceId('not-a-uuid');
    }
}
