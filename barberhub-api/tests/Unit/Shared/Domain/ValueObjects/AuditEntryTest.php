<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain\ValueObjects;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\ValueObjects\AuditAction;
use Src\Shared\Domain\ValueObjects\AuditEntry;
use Src\Shared\Domain\ValueObjects\UserId;

/** The entry keeps only what the action allows, and nothing when nothing allowed changed (SE-04). */
final class AuditEntryTest extends TestCase
{
    private const string ACTOR = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d48';

    private const string SUBJECT = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d22';

    /** The entry names who, what, on which subject and when, and the previous and new value of each field that changed. */
    public function test_it_keeps_the_previous_and_new_value_of_what_changed(): void
    {
        $entry = AuditEntry::of(
            new UserId(self::ACTOR),
            AuditAction::PaymentPolicyUpdated,
            'barbershop',
            self::SUBJECT,
            ['mode' => 'none', 'deposit_percentage' => null, 'cancellation_deadline_hours' => 24],
            ['mode' => 'deposit', 'deposit_percentage' => 30, 'cancellation_deadline_hours' => 24],
            new DateTimeImmutable('2030-03-01 08:00:00-06:00'),
        );

        $this->assertNotNull($entry);
        $this->assertSame([self::ACTOR, 'barbershop.payment_policy.updated', 'barbershop', self::SUBJECT], [$entry->actor->value, $entry->action->value, $entry->subjectType, $entry->subjectId]);
        $this->assertSame(['mode' => ['previous' => 'none', 'new' => 'deposit'], 'deposit_percentage' => ['previous' => null, 'new' => 30]], $entry->changes);
    }

    /** A field that is not in the allow list of the action never reaches the entry, whatever the caller passes. */
    public function test_a_field_outside_the_allow_list_is_dropped(): void
    {
        $entry = AuditEntry::of(
            new UserId(self::ACTOR),
            AuditAction::FeaturesUpdated,
            'barbershop',
            self::SUBJECT,
            ['vouchers_enabled' => false, 'password' => 'Vieja#2030', 'email' => 'ana@barberhub.test', 'phone' => '2460-0000', 'token' => '1|abc'],
            ['vouchers_enabled' => true, 'password' => 'Nueva#2030', 'email' => 'luis@barberhub.test', 'phone' => '2460-1111', 'token' => '2|def'],
            new DateTimeImmutable('2030-03-01 08:00:00-06:00'),
        );

        $this->assertNotNull($entry);
        $this->assertSame(['vouchers_enabled' => ['previous' => false, 'new' => true]], $entry->changes);
        $this->assertStringNotContainsString('Vieja', (string) json_encode($entry->changes));
        $this->assertStringNotContainsString('barberhub.test', (string) json_encode($entry->changes));
    }

    /** A credential is recorded by name only: that it changed, without the previous or the new value. */
    public function test_a_credential_is_recorded_by_name_only(): void
    {
        $entry = AuditEntry::of(
            new UserId(self::ACTOR),
            AuditAction::PaymentGatewayUpdated,
            'barbershop',
            self::SUBJECT,
            ['gateway' => 'onvo', 'credentials' => 'onvo_test_secret_vieja'],
            ['gateway' => 'onvo', 'credentials' => 'onvo_test_secret_nueva'],
            new DateTimeImmutable('2030-03-01 08:00:00-06:00'),
        );

        $this->assertNotNull($entry);
        $this->assertSame(['credentials' => ['changed' => true]], $entry->changes);
        $this->assertStringNotContainsString('secret', (string) json_encode($entry->changes));
    }

    /** A value that is not a scalar is not kept. */
    public function test_a_value_that_is_not_plain_is_not_kept(): void
    {
        $entry = AuditEntry::of(
            new UserId(self::ACTOR),
            AuditAction::FeaturesUpdated,
            'barbershop',
            self::SUBJECT,
            ['vouchers_enabled' => ['secret' => 'x']],
            ['vouchers_enabled' => true],
            new DateTimeImmutable('2030-03-01 08:00:00-06:00'),
        );

        $this->assertNotNull($entry);
        $this->assertSame(['vouchers_enabled' => ['previous' => null, 'new' => true]], $entry->changes);
    }

    /** When nothing allowed changed there is no entry. */
    public function test_nothing_changed_gives_no_entry(): void
    {
        $same = ['mode' => 'none', 'gateway' => null, 'password' => 'a'];

        $this->assertNull(AuditEntry::of(new UserId(self::ACTOR), AuditAction::PaymentGatewayUpdated, 'barbershop', self::SUBJECT, $same, [...$same, 'password' => 'b'], new DateTimeImmutable('2030-03-01 08:00:00-06:00')));
    }
}
