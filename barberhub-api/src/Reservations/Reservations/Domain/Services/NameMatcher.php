<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Services;

/**
 * Finds which names of a catalog a text of a person refers to (IA-02), with a plain and predictable comparison: lower case,
 * no accents and no signs. There is no fuzzy matching and no model involved: what is not clearly one name is not guessed.
 */
final class NameMatcher
{
    private const array ACCENTS = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n',
    ];

    /** Lower case, without accents or signs, with the words separated by one space. */
    public static function normalize(string $text): string
    {
        $plain = strtr(mb_strtolower($text, 'UTF-8'), self::ACCENTS);
        $plain = preg_replace('/\p{Mn}/u', '', $plain) ?? '';
        $plain = preg_replace('/[^a-z0-9]+/', ' ', $plain) ?? '';

        return trim($plain);
    }

    /**
     * The identifiers of the names the text refers to: the ones equal to it once normalized or, if there are none, the ones
     * that contain its words as whole words. One identifier is a match; several are an ambiguity; none, an unknown name.
     *
     * @param  array<string, string>  $names  Name by identifier.
     * @return list<string>
     */
    public function match(string $text, array $names): array
    {
        $wanted = self::normalize($text);

        if ($wanted === '') {
            return [];
        }

        $normalized = array_map(self::normalize(...), $names);
        $exact = array_keys(array_filter($normalized, fn (string $name): bool => $name === $wanted));

        if ($exact !== []) {
            return array_map(strval(...), $exact);
        }

        $contained = array_keys(array_filter($normalized, fn (string $name): bool => str_contains(" {$name} ", " {$wanted} ")));

        return array_map(strval(...), $contained);
    }
}
