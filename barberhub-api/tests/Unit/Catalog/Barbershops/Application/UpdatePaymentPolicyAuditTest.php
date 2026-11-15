<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Barbershops\Application;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Src\Catalog\Barbershops\Application\DTOs\UpdatePaymentPolicyCommand;
use Src\Catalog\Barbershops\Application\UseCases\UpdatePaymentPolicy;
use Src\Catalog\Barbershops\Domain\Entities\Barbershop;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Catalog\Barbershops\Domain\ValueObjects\PublicLink;
use Src\Shared\Domain\ValueObjects\AuditAction;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Phone;
use Tests\Support\Catalog\InMemoryBarbershopRepository;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\InMemoryAuditLog;
use Tests\Support\Shared\RecordingTransactionRunner;

/** Each change of the payment policy and of the gateway leaves its entry in the technical record (RF-08, RF-23, SE-04). */
final class UpdatePaymentPolicyAuditTest extends TestCase
{
    private const string BARBERSHOP = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d22';

    private const string OWNER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d48';

    private InMemoryAuditLog $audit;

    private UpdatePaymentPolicy $update;

    /** A barbershop that was just registered, as the current one. */
    protected function setUp(): void
    {
        $barbershops = new InMemoryBarbershopRepository;
        $barbershops->save(Barbershop::create(new BarbershopId(self::BARBERSHOP), new BarbershopName('Pura Navaja'), new City('San Carlos'), new Phone('2460-0000'), new PublicLink('pura-navaja')));
        $this->audit = new InMemoryAuditLog;
        $this->update = new UpdatePaymentPolicy($barbershops, new FixedBarbershopContext(new BarbershopId(self::BARBERSHOP)), $this->audit, new RecordingTransactionRunner);
    }

    /** The policy and the gateway are two entries, each with the author, the instant and the previous and new value. */
    public function test_a_change_of_policy_and_gateway_leaves_two_entries(): void
    {
        $now = new DateTimeImmutable('2030-03-01 08:00:00-06:00');

        $this->update->execute(new UpdatePaymentPolicyCommand('deposit', 30, 4, 'onvo'), self::OWNER, $now);

        $entries = $this->audit->entries();
        $this->assertCount(2, $entries);
        $this->assertSame([AuditAction::PaymentPolicyUpdated, AuditAction::PaymentGatewayUpdated], [$entries[0]->action, $entries[1]->action]);
        $this->assertSame([self::OWNER, self::OWNER], [$entries[0]->actor->value, $entries[1]->actor->value]);
        $this->assertEquals([$now, $now], [$entries[0]->at, $entries[1]->at]);
        $this->assertSame([
            'mode' => ['previous' => 'none', 'new' => 'deposit'],
            'deposit_percentage' => ['previous' => null, 'new' => 30],
            'cancellation_deadline_hours' => ['previous' => 24, 'new' => 4],
        ], $entries[0]->changes);
        $this->assertSame(['gateway' => ['previous' => null, 'new' => 'onvo']], $entries[1]->changes);
    }

    /** Changing only the gateway leaves only the gateway entry; changing nothing leaves none. */
    public function test_only_what_changed_is_recorded(): void
    {
        $now = new DateTimeImmutable('2030-03-01 08:00:00-06:00');
        $this->update->execute(new UpdatePaymentPolicyCommand('full', null, null, 'onvo'), self::OWNER, $now);
        $firstEntries = count($this->audit->entries());

        $this->update->execute(new UpdatePaymentPolicyCommand(null, null, null, 'paypal'), self::OWNER, $now);
        $this->update->execute(new UpdatePaymentPolicyCommand(null, null, null, 'paypal'), self::OWNER, $now);

        $entries = $this->audit->entries();
        $this->assertCount($firstEntries + 1, $entries);
        $this->assertSame(AuditAction::PaymentGatewayUpdated, $entries[$firstEntries]->action);
        $this->assertSame(['gateway' => ['previous' => 'onvo', 'new' => 'paypal']], $entries[$firstEntries]->changes);
    }
}
