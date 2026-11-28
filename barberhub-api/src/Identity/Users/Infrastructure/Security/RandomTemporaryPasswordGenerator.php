<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Security;

use Src\Identity\Users\Domain\Contracts\TemporaryPasswordGenerator;

final readonly class RandomTemporaryPasswordGenerator implements TemporaryPasswordGenerator
{
    private const int LENGTH = 16;

    /** A random password of 16 characters that meets the password policy (SE-07): one of each kind and the rest from all of them. */
    public function generate(): string
    {
        $kinds = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '!#$%&*+-=?@^_'];
        $characters = array_map(fn (string $kind): string => $kind[random_int(0, strlen($kind) - 1)], $kinds);
        $all = implode('', $kinds);

        while (count($characters) < self::LENGTH) {
            $characters[] = $all[random_int(0, strlen($all) - 1)];
        }

        for ($index = count($characters) - 1; $index > 0; $index--) {
            $other = random_int(0, $index);
            [$characters[$index], $characters[$other]] = [$characters[$other], $characters[$index]];
        }

        return implode('', $characters);
    }
}
