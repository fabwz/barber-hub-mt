<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Users\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SensitiveParameter;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\Phone;
use Src\Identity\Users\Domain\ValueObjects\PlainPassword;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final class ValueObjectsTest extends TestCase
{
    /** The email is trimmed and lower-cased, so one address has one spelling (CL-01). */
    public function test_email_is_normalised(): void
    {
        $this->assertSame('ana.mora@barberhub.test', new Email('  Ana.Mora@BarberHub.TEST ')->value);
    }

    /** The longest address a mail server delivers fits in the column. */
    public function test_email_accepts_the_longest_deliverable_address(): void
    {
        $address = str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 61);

        $this->assertSame(254, mb_strlen(new Email($address)->value));
    }

    /** A malformed or oversized email is rejected with a translatable error. */
    #[DataProvider('invalidEmails')]
    public function test_rejects_invalid_emails(string $email): void
    {
        try {
            new Email($email);
            $this->fail('An invalid email was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame('identity.email_invalid', $exception->translationKey());
        }
    }

    /**
     * Emails that break the invariant of the value object.
     *
     * @return array<string, array{string}>
     */
    public static function invalidEmails(): array
    {
        return [
            'blank' => ['   '],
            'without at sign' => ['ana.barberhub.test'],
            'without domain' => ['ana@'],
            'too long' => [str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 63)],
        ];
    }

    /** The name is trimmed and rejects blank or oversized values. */
    public function test_name_is_trimmed_and_limited(): void
    {
        $this->assertSame('Ana Mora', new UserName('  Ana Mora ')->value);
        $this->assertSame(120, mb_strlen(new UserName(str_repeat('ñ', 120))->value));

        foreach (['   ', str_repeat('a', 121)] as $name) {
            try {
                new UserName($name);
                $this->fail('An invalid name was accepted.');
            } catch (InvalidValueException $exception) {
                $this->assertSame('identity.name_invalid', $exception->translationKey());
            }
        }
    }

    /** The phone is trimmed and rejects blank or oversized values, with no format imposed. */
    public function test_phone_is_trimmed_and_limited(): void
    {
        $this->assertSame('+506 8888-0000', new Phone(' +506 8888-0000 ')->value);
        $this->assertSame(30, mb_strlen(new Phone(str_repeat('1', 30))->value));

        foreach (['   ', str_repeat('1', 31)] as $phone) {
            try {
                new Phone($phone);
                $this->fail('An invalid phone was accepted.');
            } catch (InvalidValueException $exception) {
                $this->assertSame('identity.phone_invalid', $exception->translationKey());
            }
        }
    }

    /** A password that meets SE-07 is kept exactly as typed, spaces and accents included. */
    public function test_plain_password_is_kept_as_typed(): void
    {
        $this->assertSame('Clave#segura1', new PlainPassword('Clave#segura1')->value);
        $this->assertSame('  Ñandú 8!  ', new PlainPassword('  Ñandú 8!  ')->value);
    }

    /** The minimum counts characters, not bytes. */
    public function test_plain_password_counts_characters(): void
    {
        $this->assertSame('Ññññ1ñ!ñ', new PlainPassword('Ññññ1ñ!ñ')->value);
        $this->assertSame(['identity.password_too_short'], PlainPassword::missingRequirements('Ññ1ñ!ñ'));
    }

    /**
     * Every requirement that is missing is reported, in a fixed order; the constructor raises the first one.
     *
     * @param  list<string>  $missing
     */
    #[DataProvider('passwords')]
    public function test_missing_requirements_of_a_password(string $password, array $missing): void
    {
        $this->assertSame($missing, PlainPassword::missingRequirements($password));

        if ($missing === []) {
            $this->assertSame($password, new PlainPassword($password)->value);

            return;
        }

        try {
            new PlainPassword($password);
            $this->fail('An invalid password was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame($missing[0], $exception->translationKey());
        }
    }

    /**
     * Passwords and the requirements of SE-07 they miss.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function passwords(): array
    {
        return [
            'valid' => ['Abcdef1!', []],
            'empty' => ['', [
                'identity.password_too_short',
                'identity.password_without_uppercase',
                'identity.password_without_lowercase',
                'identity.password_without_number',
                'identity.password_without_symbol',
            ]],
            'abc' => ['abc', [
                'identity.password_too_short',
                'identity.password_without_uppercase',
                'identity.password_without_number',
                'identity.password_without_symbol',
            ]],
            'short only' => ['Ab1!', ['identity.password_too_short']],
            'without uppercase' => ['abcdef1!', ['identity.password_without_uppercase']],
            'without lowercase' => ['ABCDEF1!', ['identity.password_without_lowercase']],
            'without number' => ['Abcdefg!', ['identity.password_without_number']],
            'without symbol' => ['Abcdefg1', ['identity.password_without_symbol']],
            'a space is not a symbol' => ['Abcdefg 1', ['identity.password_without_symbol']],
            'a punctuation mark is a symbol' => ['Abcdefg#1', []],
        ];
    }

    /** The password stays out of stack traces. */
    public function test_plain_password_is_marked_as_sensitive(): void
    {
        foreach (['__construct', 'missingRequirements'] as $method) {
            $parameter = new ReflectionMethod(PlainPassword::class, $method)->getParameters()[0];

            $this->assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        }
    }
}
