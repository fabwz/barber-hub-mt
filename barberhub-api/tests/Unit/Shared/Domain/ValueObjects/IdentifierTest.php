<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain\ValueObjects;

use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\Identifier;

final class IdentifierTest extends TestCase
{
    private const string UUID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d11';

    /** A canonical UUID is accepted as-is. */
    public function test_accepts_a_canonical_uuid(): void
    {
        $this->assertSame(self::UUID, new TestIdentifier(self::UUID)->value);
    }

    /** Malformed values are rejected with a translatable error. */
    public function test_rejects_a_malformed_value(): void
    {
        try {
            new TestIdentifier('not-a-uuid');
            $this->fail('A malformed identifier was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame('shared.invalid_identifier', $exception->translationKey());
        }
    }

    /** Uppercase UUIDs are not canonical. */
    public function test_rejects_an_uppercase_uuid(): void
    {
        $this->expectException(InvalidValueException::class);

        new TestIdentifier(strtoupper(self::UUID));
    }

    /** Equality requires the same type and the same value. */
    public function test_compares_by_type_and_value(): void
    {
        $identifier = new TestIdentifier(self::UUID);

        $this->assertTrue($identifier->equals(new TestIdentifier(self::UUID)));
        $this->assertFalse($identifier->equals(new OtherTestIdentifier(self::UUID)));
        $this->assertFalse($identifier->equals(new TestIdentifier('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d12')));
    }
}

final readonly class TestIdentifier extends Identifier {}

final readonly class OtherTestIdentifier extends Identifier {}
