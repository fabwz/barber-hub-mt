<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Barbershops\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Catalog\Barbershops\Domain\Entities\Barbershop;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentPolicy;
use Src\Catalog\Barbershops\Domain\ValueObjects\PublicLink;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Phone;

final class BarbershopTest extends TestCase
{
    private const string BARBERSHOP_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d22';

    /** A barbershop is registered with its name, city and contact phone. */
    public function test_barbershop_can_be_created(): void
    {
        $barbershop = Barbershop::create(
            new BarbershopId(self::BARBERSHOP_ID),
            new BarbershopName('Barbería Pura Navaja'),
            new City('San Carlos'),
            new Phone('2460-0000'),
            new PublicLink('pura-navaja'),
        );

        $this->assertSame(self::BARBERSHOP_ID, $barbershop->id->value);
        $this->assertSame('Barbería Pura Navaja', $barbershop->name->value);
        $this->assertSame('San Carlos', $barbershop->city->value);
        $this->assertSame('2460-0000', $barbershop->phone->value);
        $this->assertSame('pura-navaja', $barbershop->link->value);
    }

    /** A stored barbershop is rebuilt with the state it was saved with. */
    public function test_barbershop_can_be_reconstituted(): void
    {
        $barbershop = Barbershop::reconstitute(
            new BarbershopId(self::BARBERSHOP_ID),
            new BarbershopName('Barbería Pura Navaja'),
            new City('San Carlos'),
            new Phone('2460-0000'),
            new PublicLink('pura-navaja'),
            PaymentPolicy::initial(),
        );

        $this->assertSame('Barbería Pura Navaja', $barbershop->name->value);
        $this->assertSame('pura-navaja', $barbershop->link->value);
    }

    /** The name and the city are trimmed and limited in length. */
    public function test_name_and_city_are_trimmed_and_limited(): void
    {
        $this->assertSame('Pura Navaja', new BarbershopName('  Pura Navaja  ')->value);
        $this->assertSame(120, mb_strlen(new BarbershopName(str_repeat('ñ', 120))->value));
        $this->assertSame('Ciudad Quesada', new City('  Ciudad Quesada ')->value);
        $this->assertSame(80, mb_strlen(new City(str_repeat('ñ', 80))->value));
    }

    /** A blank or oversized name is rejected with a translatable error. */
    #[DataProvider('invalidNames')]
    public function test_name_rejects_invalid_values(string $name): void
    {
        try {
            new BarbershopName($name);
            $this->fail('An invalid barbershop name was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame('barbershops.name_invalid', $exception->translationKey());
        }
    }

    /**
     * Names that break the invariant of the value object.
     *
     * @return array<string, array{string}>
     */
    public static function invalidNames(): array
    {
        return ['empty' => [''], 'blank' => ['   '], 'too long' => [str_repeat('a', 121)]];
    }

    /** A blank or oversized city is rejected with a translatable error. */
    #[DataProvider('invalidCities')]
    public function test_city_rejects_invalid_values(string $city): void
    {
        try {
            new City($city);
            $this->fail('An invalid city was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame('barbershops.city_invalid', $exception->translationKey());
        }
    }

    /**
     * Cities that break the invariant of the value object.
     *
     * @return array<string, array{string}>
     */
    public static function invalidCities(): array
    {
        return ['empty' => [''], 'blank' => ['   '], 'too long' => [str_repeat('a', 81)]];
    }

    /** The identifier only accepts a canonical UUID. */
    public function test_id_rejects_a_malformed_value(): void
    {
        $this->expectException(InvalidValueException::class);

        new BarbershopId('not-a-uuid');
    }
}
