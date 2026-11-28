<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Barbershops\Application;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Catalog\Barbershops\Application\DTOs\RegisterBarbershopCommand;
use Src\Catalog\Barbershops\Application\UseCases\RegisterBarbershop;
use Src\Catalog\Barbershops\Domain\Entities\Barbershop;
use Src\Catalog\Barbershops\Domain\Exceptions\PublicLinkUnavailableException;
use Src\Catalog\Barbershops\Domain\Services\PublicLinkGenerator;
use Src\Identity\Memberships\Domain\Exceptions\OperatorCannotHoldRoleException;
use Src\Identity\Memberships\Domain\Exceptions\RoleAlreadyAssignedException;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\AuditAction;
use Tests\Support\Catalog\InlineBarbershopScope;
use Tests\Support\Catalog\InMemoryBarbershopRepository;
use Tests\Support\Catalog\InMemoryOwnerAccounts;
use Tests\Support\Catalog\InMemoryOwnerMemberships;
use Tests\Support\Catalog\InMemoryRentInstallments;
use Tests\Support\Shared\InMemoryAuditLog;
use Tests\Support\Shared\MutableClock;
use Tests\Support\Shared\RecordingTransactionRunner;

final class RegisterBarbershopTest extends TestCase
{
    private const string OWNER_ID = InMemoryOwnerAccounts::OWNER_ID;

    private const string OPERATOR_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d55';

    private InMemoryBarbershopRepository $barbershops;

    private InMemoryOwnerMemberships $owners;

    private RecordingTransactionRunner $transaction;

    private InMemoryRentInstallments $rent;

    private InMemoryOwnerAccounts $accounts;

    private InlineBarbershopScope $scope;

    private InMemoryAuditLog $audit;

    private RegisterBarbershop $registerBarbershop;

    /** Wire the use case to in-memory ports. */
    protected function setUp(): void
    {
        $this->barbershops = new InMemoryBarbershopRepository;
        $this->owners = new InMemoryOwnerMemberships;
        $this->transaction = new RecordingTransactionRunner;
        $this->rent = new InMemoryRentInstallments;
        $this->accounts = new InMemoryOwnerAccounts;
        $this->scope = new InlineBarbershopScope;
        $this->audit = new InMemoryAuditLog;
        $this->wire($this->accounts);
    }

    /** Build the use case around the given source of owner accounts. */
    private function wire(InMemoryOwnerAccounts $accounts): void
    {
        $this->registerBarbershop = new RegisterBarbershop(
            $this->barbershops,
            $accounts,
            $this->owners,
            $this->transaction,
            new PublicLinkGenerator,
            $this->rent,
            $this->scope,
            $this->audit,
            new MutableClock(new DateTimeImmutable('2026-11-10 09:00:00-06:00')),
        );
    }

    /** The account of the owner is created: its temporary password comes back and the outcome says so (RF-01, UP-04). */
    public function test_a_new_owner_account_returns_its_temporary_password_once(): void
    {
        $registered = $this->registerBarbershop->execute($this->command());

        $this->assertTrue($registered->ownerAccountCreated);
        $this->assertSame('Temporal#Clave1', $registered->temporaryPassword);
        $this->assertSame('rodrigo@barberhub.test', $registered->ownerEmail);
        $this->assertSame([['name' => 'Rodrigo Mora', 'email' => 'Rodrigo@Barberhub.test', 'phone' => '8888-0000']], $this->accounts->requested());
    }

    /** An email that already has an account gives no password: the outcome says it already existed (RF-01, UP-05). */
    public function test_an_existing_owner_account_has_no_password_to_show(): void
    {
        $this->wire(new InMemoryOwnerAccounts(temporaryPassword: null));

        $registered = $this->registerBarbershop->execute($this->command());

        $this->assertFalse($registered->ownerAccountCreated);
        $this->assertNull($registered->temporaryPassword);
        $this->assertSame([['barbershopId' => $registered->barbershop->id->value, 'userId' => self::OWNER_ID]], $this->owners->assigned());
    }

    /** The registration is recorded as the operator's, as the new barbershop, with no contact data and no password (SE-04). */
    public function test_the_registration_is_recorded_with_the_operator_as_author_and_no_sensitive_data(): void
    {
        $registered = $this->registerBarbershop->execute($this->command());

        $entries = $this->audit->entries();
        $this->assertCount(1, $entries);
        $this->assertSame(self::OPERATOR_ID, $entries[0]->actor?->value);
        $this->assertSame(AuditAction::BarbershopRegistered, $entries[0]->action);
        $this->assertSame($registered->barbershop->id->value, $entries[0]->subjectId);
        $this->assertSame([$registered->barbershop->id->value], $this->scope->scopes());
        $this->assertSame(
            ['public_link' => ['previous' => null, 'new' => 'pura-navaja'], 'owner_user_id' => ['previous' => null, 'new' => self::OWNER_ID], 'owner_account_created' => ['previous' => null, 'new' => true]],
            $entries[0]->changes,
        );

        $recorded = json_encode($entries[0]->changes, JSON_THROW_ON_ERROR);
        foreach (['Temporal#Clave1', 'rodrigo@barberhub.test', '8888-0000', '2460-0000'] as $secret) {
            $this->assertStringNotContainsString($secret, $recorded);
        }
    }

    /** A failure of the owner role leaves no barbershop, no installment and no entry (RF-01). */
    public function test_a_failure_leaves_nothing_of_the_registration(): void
    {
        $this->owners->failWith(OperatorCannotHoldRoleException::create());

        try {
            $this->register($this->command());
            $this->fail('The refusal was swallowed.');
        } catch (OperatorCannotHoldRoleException) {
            $this->assertSame(1, $this->transaction->rolledBack());
            $this->assertSame([], $this->rent->opened());
            $this->assertSame([], $this->audit->entries());
        }
    }

    /** The use case answers the outcome and most tests care only about the barbershop. */
    private function register(RegisterBarbershopCommand $command): Barbershop
    {
        return $this->registerBarbershop->execute($command)->barbershop;
    }

    /** The first rent installment is opened for the new barbershop at the instant of the registration, in the same transaction (RF-01, RF-57). */
    public function test_the_first_rent_installment_is_opened_with_the_registration(): void
    {
        $barbershop = $this->register($this->command());

        $opened = $this->rent->opened();
        $this->assertCount(1, $opened);
        $this->assertSame($barbershop->id->value, $opened[0]['barbershopId']);
        $this->assertSame('2026-11-10 09:00:00', $opened[0]['now']->format('Y-m-d H:i:s'));
    }

    /** The first barbershop of a name gets the link the name gives, without the initial word "Barbería" (RF-02). */
    public function test_barbershop_gets_the_link_of_its_name(): void
    {
        $barbershop = $this->register($this->command());

        $this->assertSame('pura-navaja', $barbershop->link->value);
        $this->assertSame(1, $this->barbershops->saveAttempts());
    }

    /** The second and the third barbershop with the same name get the next number (RF-02, MT-07). */
    public function test_homonyms_get_the_next_number(): void
    {
        $links = [
            $this->register($this->command())->link->value,
            $this->register($this->command(name: 'Pura Navaja'))->link->value,
            $this->register($this->command(name: 'BARBERÍA PURA NAVAJA'))->link->value,
        ];

        $this->assertSame(['pura-navaja', 'pura-navaja-2', 'pura-navaja-3'], $links);
    }

    /** The unique key decides: a clash is answered by trying the next number, with no lookup beforehand. */
    public function test_each_clash_is_answered_with_the_next_number(): void
    {
        $this->register($this->command());
        $this->register($this->command());
        $attempts = $this->barbershops->saveAttempts();

        $third = $this->register($this->command());

        $this->assertSame('pura-navaja-3', $third->link->value);
        $this->assertSame(3, $this->barbershops->saveAttempts() - $attempts);
        $this->assertCount(3, $this->owners->assigned());
    }

    /** When every allowed link is taken the registration fails clearly and leaves nothing behind. */
    public function test_exhausted_attempts_fail_and_roll_back(): void
    {
        for ($i = 0; $i < RegisterBarbershop::MAX_LINK_ATTEMPTS; $i++) {
            $this->register($this->command());
        }

        $registered = count($this->barbershops->all());
        $assigned = count($this->owners->assigned());

        try {
            $this->register($this->command());
            $this->fail('A link was assigned beyond the allowed attempts.');
        } catch (PublicLinkUnavailableException $exception) {
            $this->assertSame('barbershops.public_link_unavailable', $exception->translationKey());
            $this->assertSame($registered, count($this->barbershops->all()));
            $this->assertCount($assigned, $this->owners->assigned());
            $this->assertSame(1, $this->transaction->rolledBack());
        }
    }

    /** The barbershop and its owner are registered together in a single transaction. */
    public function test_barbershop_and_owner_are_registered_in_one_transaction(): void
    {
        $barbershop = $this->register($this->command(name: '  Barbería Pura Navaja '));

        $this->assertSame('Barbería Pura Navaja', $barbershop->name->value);
        $this->assertSame('San Carlos', $barbershop->city->value);
        $this->assertSame('2460-0000', $barbershop->phone->value);
        $this->assertEquals([$barbershop], $this->barbershops->all());
        $this->assertSame([['barbershopId' => $barbershop->id->value, 'userId' => self::OWNER_ID]], $this->owners->assigned());
        $this->assertSame(1, $this->transaction->runs());
        $this->assertSame(1, $this->transaction->committed());
        $this->assertSame(0, $this->transaction->rolledBack());
    }

    /** A refusal of the owner assignment rolls the transaction back and reaches the caller. */
    public function test_owner_refusal_rolls_the_transaction_back(): void
    {
        $failure = RoleAlreadyAssignedException::create();
        $this->owners->failWith($failure);

        try {
            $this->register($this->command());
            $this->fail('The refusal of the owner assignment was swallowed.');
        } catch (RoleAlreadyAssignedException $exception) {
            $this->assertSame($failure, $exception);
            $this->assertSame(1, $this->transaction->runs());
            $this->assertSame(0, $this->transaction->committed());
            $this->assertSame(1, $this->transaction->rolledBack());
        }
    }

    /** Invalid data is refused before a transaction is opened. */
    #[DataProvider('invalidData')]
    public function test_invalid_data_is_refused_before_the_transaction(string $name, string $city, string $phone, string $key): void
    {
        try {
            $this->register($this->command($name, $city, $phone));
            $this->fail('Invalid data was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame($key, $exception->translationKey());
            $this->assertSame(0, $this->transaction->runs());
            $this->assertSame([], $this->owners->assigned());
        }
    }

    /**
     * Commands that break one value object each, and the translation key reported.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function invalidData(): array
    {
        return [
            'blank name' => ['   ', 'San Carlos', '2460-0000', 'barbershops.name_invalid'],
            'blank city' => ['Pura Navaja', '   ', '2460-0000', 'barbershops.city_invalid'],
            'blank phone' => ['Pura Navaja', 'San Carlos', '   ', 'shared.phone_invalid'],
        ];
    }

    /** Build the command of a barbershop, overriding only what a scenario changes. */
    private function command(string $name = 'Barbería Pura Navaja', string $city = 'San Carlos', string $phone = '2460-0000'): RegisterBarbershopCommand
    {
        return new RegisterBarbershopCommand($name, $city, $phone, 'Rodrigo Mora', 'Rodrigo@Barberhub.test', '8888-0000', self::OPERATOR_ID);
    }
}
