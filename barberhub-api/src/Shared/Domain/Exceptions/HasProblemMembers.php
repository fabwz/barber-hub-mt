<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Exceptions;

interface HasProblemMembers
{
    /**
     * Extra members of the problem document that describe this error, such as the alternatives to a space that is taken.
     * The names are the JSON attributes of the API (RD-07), so they are in Spanish; the values are plain data, and a
     * name the document already uses (`type`, `title`, `status`, `detail`, `instance`) is ignored.
     *
     * @return array<string, mixed>
     */
    public function problemMembers(): array;
}
