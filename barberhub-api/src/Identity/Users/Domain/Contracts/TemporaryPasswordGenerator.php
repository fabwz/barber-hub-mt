<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Contracts;

interface TemporaryPasswordGenerator
{
    /** A random password that meets the password policy (SE-07), to hand over once and be changed at the first login (UP-04). */
    public function generate(): string;
}
