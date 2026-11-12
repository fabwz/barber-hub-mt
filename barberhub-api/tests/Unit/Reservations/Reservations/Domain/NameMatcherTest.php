<?php

declare(strict_types=1);

namespace Tests\Unit\Reservations\Reservations\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Reservations\Reservations\Domain\Services\NameMatcher;

final class NameMatcherTest extends TestCase
{
    private const array CATALOG = ['a' => 'Corte clásico', 'b' => 'Corte + barba', 'c' => 'Barba', 'd' => 'Degradado'];

    /**
     * @return array<string, array{string, string}>
     */
    public static function normalizations(): array
    {
        return [
            'case' => ['DEGRADADO', 'degradado'],
            'accents' => ['Clásico Ñandú Pingüino', 'clasico nandu pinguino'],
            'signs' => ['corte + barba!!', 'corte barba'],
            'spaces' => ["  corte \t  barba  ", 'corte barba'],
            'injection' => ["corte'; drop table x; --", 'corte drop table x'],
            'only signs' => ['!!!', ''],
        ];
    }

    #[DataProvider('normalizations')]
    public function test_a_text_is_normalized(string $text, string $expected): void
    {
        $this->assertSame($expected, NameMatcher::normalize($text));
    }

    /** A combined accent (the letter and the mark apart) is read like the precomposed one. */
    public function test_a_decomposed_accent_is_removed(): void
    {
        $this->assertSame('clasico', NameMatcher::normalize("cla\u{0301}sico"));
    }

    /** An exact name wins even when the text is also contained in other names. */
    public function test_an_exact_name_wins_over_the_names_that_contain_it(): void
    {
        $this->assertSame(['c'], (new NameMatcher)->match('BARBA', self::CATALOG));
    }

    /** Without an exact name, the text must be contained in the names, as whole words. */
    public function test_a_contained_text_matches_every_name_that_has_it(): void
    {
        $this->assertSame(['a', 'b'], (new NameMatcher)->match('corte', self::CATALOG));
        $this->assertSame(['d'], (new NameMatcher)->match('degradado', self::CATALOG));
    }

    /** Part of a word is not a word: there are no approximations. */
    public function test_part_of_a_word_does_not_match(): void
    {
        $this->assertSame([], (new NameMatcher)->match('degrad', self::CATALOG));
        $this->assertSame([], (new NameMatcher)->match('orte', self::CATALOG));
    }

    /** A text longer than the name, or with other words, matches nothing. */
    public function test_a_longer_text_does_not_match(): void
    {
        $this->assertSame([], (new NameMatcher)->match('corte clásico premium', self::CATALOG));
        $this->assertSame([], (new NameMatcher)->match('corte y barba', self::CATALOG));
    }

    /** An empty text, or one of signs only, matches nothing. */
    public function test_an_empty_text_matches_nothing(): void
    {
        $this->assertSame([], (new NameMatcher)->match('', self::CATALOG));
        $this->assertSame([], (new NameMatcher)->match(' !! ', self::CATALOG));
    }

    /** Two names that are the same once normalized are an ambiguity, not a match. */
    public function test_two_equal_names_are_both_returned(): void
    {
        $this->assertSame(['x', 'y'], (new NameMatcher)->match('Corte', ['x' => 'corte', 'y' => 'CORTE']));
    }
}
