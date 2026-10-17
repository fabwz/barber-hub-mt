<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain\Exceptions;

use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Exceptions\DomainException;

final class DomainExceptionTest extends TestCase
{
    /** The exception carries the translation key and its placeholders. */
    public function test_carries_the_translation_key_and_parameters(): void
    {
        $exception = new TestDomainException('shared.example', ['hours' => 4, 'name' => 'Ana']);

        $this->assertSame('shared.example', $exception->translationKey());
        $this->assertSame(['hours' => 4, 'name' => 'Ana'], $exception->parameters());
    }

    /** Without placeholders the parameters are empty. */
    public function test_parameters_default_to_empty(): void
    {
        $this->assertSame([], new TestDomainException('shared.example')->parameters());
    }

    /** The key doubles as the technical message, never user-facing text. */
    public function test_uses_the_key_as_the_technical_message(): void
    {
        $this->assertSame('shared.example', new TestDomainException('shared.example')->getMessage());
    }
}

final class TestDomainException extends DomainException {}
