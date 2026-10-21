<?php

declare(strict_types=1);

namespace Src\Shared\Domain\ValueObjects;

use Src\Shared\Domain\Exceptions\InvalidValueException;

final readonly class PageRequest
{
    public const int DEFAULT_SIZE = 15;

    public const int MAX_SIZE = 50;

    /** Ask for a page of a listing: its number, from 1, and how many elements it holds, from 1 to 50. */
    public function __construct(
        public int $number = 1,
        public int $size = self::DEFAULT_SIZE,
    ) {
        if ($number < 1 || $size < 1 || $size > self::MAX_SIZE) {
            throw new InvalidValueException('shared.page_out_of_range', ['max' => self::MAX_SIZE]);
        }
    }

    /** Elements that come before the page. */
    public function offset(): int
    {
        return ($this->number - 1) * $this->size;
    }
}
