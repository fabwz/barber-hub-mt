<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\Services;

use InvalidArgumentException;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\PublicLink;

final readonly class PublicLinkGenerator
{
    /** Longest base, so the number appended to a homonym always fits in the link. */
    private const int MAX_BASE_LENGTH = 100;

    /** Link used when the name has no letter or digit to build one from. */
    private const string FALLBACK = 'barberia';

    /** Word the link leaves out when the name has more words (RF-02). */
    private const string OMITTED_WORD = 'barberia';

    /** Precomposed accented letters and their plain counterpart. */
    private const array ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y',
    ];

    /**
     * Build the link of the n-th barbershop with this name: the plain link for the first one and the number
     * appended for each homonym after it (`pura-navaja`, `pura-navaja-2`, `pura-navaja-3`).
     */
    public function candidate(BarbershopName $name, int $number): PublicLink
    {
        if ($number < 1) {
            throw new InvalidArgumentException('The number of a link starts at 1.');
        }

        $base = $this->base($name);

        return new PublicLink($number === 1 ? $base : "{$base}-{$number}");
    }

    /** Lowercase the name, strip its accents and join its words with hyphens. */
    private function base(BarbershopName $name): string
    {
        $folded = strtr(mb_strtolower($name->value), self::ACCENTS);
        $folded = preg_replace('/\p{Mn}/u', '', $folded) ?? $folded;

        /** @var list<string> $words */
        $words = preg_split('/[^a-z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY);

        if (count($words) > 1 && $words[0] === self::OMITTED_WORD) {
            array_shift($words);
        }

        $base = rtrim(substr(implode('-', $words), 0, self::MAX_BASE_LENGTH), '-');

        return $base === '' ? self::FALLBACK : $base;
    }
}
