<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Barbershops\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Catalog\Barbershops\Domain\Services\PublicLinkGenerator;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\PublicLink;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final class PublicLinkTest extends TestCase
{
    /** A link of lowercase letters and digits in hyphenated groups is accepted as it comes. */
    #[DataProvider('validLinks')]
    public function test_link_accepts_lowercase_digits_and_hyphens(string $value): void
    {
        $this->assertSame($value, new PublicLink($value)->value);
    }

    /**
     * Links within the format.
     *
     * @return array<string, array{string}>
     */
    public static function validLinks(): array
    {
        return [
            'one word' => ['navaja'],
            'hyphenated' => ['pura-navaja'],
            'numbered' => ['pura-navaja-2'],
            'digits only' => ['24'],
            'longest' => [str_repeat('a', 120)],
        ];
    }

    /** Anything that is not a plain lowercase, hyphenated link is rejected with a translatable error. */
    #[DataProvider('invalidLinks')]
    public function test_link_rejects_other_values(string $value): void
    {
        try {
            new PublicLink($value);
            $this->fail('An invalid link was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame('barbershops.public_link_invalid', $exception->translationKey());
        }
    }

    /**
     * Links that break the format.
     *
     * @return array<string, array{string}>
     */
    public static function invalidLinks(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Pura-Navaja'],
            'accent' => ['barbería'],
            'underscore' => ['pura_navaja'],
            'space' => ['pura navaja'],
            'leading hyphen' => ['-pura'],
            'trailing hyphen' => ['pura-'],
            'double hyphen' => ['pura--navaja'],
            'trailing newline' => ["pura\n"],
            'slash' => ['pura/navaja'],
            'too long' => [str_repeat('a', 121)],
        ];
    }

    /** The link is the name in lowercase, without accents and with hyphens (RF-02). */
    #[DataProvider('names')]
    public function test_generator_derives_the_link_from_the_name(string $name, string $expected): void
    {
        $this->assertSame($expected, new PublicLinkGenerator()->candidate(new BarbershopName($name), 1)->value);
    }

    /**
     * Names and the link each one gets.
     *
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'initial word omitted' => ['Barbería Pura Navaja', 'pura-navaja'],
            'initial word without accent' => ['barberia pura navaja', 'pura-navaja'],
            'initial word in capitals' => ['BARBERÍA PURA NAVAJA', 'pura-navaja'],
            'initial word mixed case' => ['bArBeRíA Pura Navaja', 'pura-navaja'],
            'initial word kept when alone' => ['Barbería', 'barberia'],
            'initial word kept when alone with symbols' => ['¡Barbería!', 'barberia'],
            'initial word kept when not first' => ['Pura Barbería', 'pura-barberia'],
            'a longer word is not the initial word' => ['Barberías Unidas', 'barberias-unidas'],
            'only the first initial word goes' => ['Barbería Barbería Norte', 'barberia-norte'],
            'accents' => ['Peluquería Ñandú Éxito', 'peluqueria-nandu-exito'],
            'diaeresis and cedilla' => ['Cigüeña Façade', 'ciguena-facade'],
            'decomposed accent' => ["Cafe\u{0301} Pura", 'cafe-pura'],
            'repeated spaces' => ['  Pura    Navaja  ', 'pura-navaja'],
            'symbols collapse' => ['Pura -- Navaja & Co.', 'pura-navaja-co'],
            'apostrophe' => ["D'Angelo", 'd-angelo'],
            'digits' => ['Navaja 24/7', 'navaja-24-7'],
            'symbols only' => ['¡¡¡', 'barberia'],
            'other alphabets leave no letter' => ['理髪店', 'barberia'],
        ];
    }

    /** The base is cut so the number of a homonym always fits in the link. */
    public function test_long_names_leave_room_for_the_number(): void
    {
        $name = new BarbershopName(str_repeat('a', 118).' b');

        $first = new PublicLinkGenerator()->candidate($name, 1)->value;
        $last = new PublicLinkGenerator()->candidate($name, 9999)->value;

        $this->assertSame(100, strlen($first));
        $this->assertSame($first.'-9999', $last);
        $this->assertLessThanOrEqual(PublicLink::MAX_LENGTH, strlen($last));
    }

    /** A cut that lands on a hyphen does not leave it hanging. */
    public function test_cut_does_not_end_with_a_hyphen(): void
    {
        $name = new BarbershopName(str_repeat('a', 99).' bbb');

        $this->assertSame(str_repeat('a', 99), new PublicLinkGenerator()->candidate($name, 1)->value);
    }

    /** The first barbershop with a name gets the plain link and each homonym after it the next number. */
    public function test_homonyms_get_consecutive_numbers(): void
    {
        $generator = new PublicLinkGenerator;
        $name = new BarbershopName('Barbería Pura Navaja');

        $this->assertSame('pura-navaja', $generator->candidate($name, 1)->value);
        $this->assertSame('pura-navaja-2', $generator->candidate($name, 2)->value);
        $this->assertSame('pura-navaja-3', $generator->candidate($name, 3)->value);
    }

    /** Numbering starts at 1. */
    public function test_number_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PublicLinkGenerator()->candidate(new BarbershopName('Pura Navaja'), 0);
    }
}
