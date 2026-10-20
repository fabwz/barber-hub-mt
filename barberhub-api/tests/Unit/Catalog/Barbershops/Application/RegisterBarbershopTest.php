<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Barbershops\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Catalog\Barbershops\Application\DTOs\RegisterBarbershopCommand;
use Src\Catalog\Barbershops\Application\UseCases\RegisterBarbershop;
use Src\Catalog\Barbershops\Domain\Exceptions\PublicLinkUnavailableException;
use Src\Catalog\Barbershops\Domain\Services\PublicLinkGenerator;
use Src\Identity\Memberships\Domain\Exceptions\RoleAlreadyAssignedException;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Tests\Support\Catalog\InMemoryBarbershopRepository;
use Tests\Support\Catalog\InMemoryOwnerMemberships;
use Tests\Support\Shared\RecordingTransactionRunner;

final class RegisterBarbershopTest extends TestCase
{
    private const string OWNER_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    private InMemoryBarbershopRepository $barbershops;

    private InMemoryOwnerMemberships $owners;

    private RecordingTransactionRunner $transaction;

    private RegisterBarbershop $registerBarbershop;

    /** Wire the use case to in-memory ports. */
    protected function setUp(): void
    {
        $this->barbershops = new InMemoryBarbershopRepository;
        $this->owners = new InMemoryOwnerMemberships;
        $this->transaction = new RecordingTransactionRunner;
        $this->registerBarbershop = new RegisterBarbershop($this->barbershops, $this->owners, $this->transaction, new PublicLinkGenerator);
    }

    /** The first barbershop of a name gets the link the name gives, without the initial word "Barbería" (RF-02). */
    public function test_barbershop_gets_the_link_of_its_name(): void
    {
        $barbershop = $this->registerBarbershop->execute($this->command());

        $this->assertSame('pura-navaja', $barbershop->link->value);
        $this->assertSame(1, $this->barbershops->saveAttempts());
    }

    /** The second and the third barbershop with the same name get the next number (RF-02, MT-07). */
    public function test_homonyms_get_the_next_number(): void
    {
        $links = [
            $this->registerBarbershop->execute($this->command())->link->value,
            $this->registerBarbershop->execute($this->command(name: 'Pura Navaja'))->link->value,
            $this->registerBarbershop->execute($this->command(name: 'BARBERÍA PURA NAVAJA'))->link->value,
        ];

        $this->assertSame(['pura-navaja', 'pura-navaja-2', 'pura-navaja-3'], $links);
    }

    /** The unique key decides: a clash is answered by trying the next number, with no lookup beforehand. */
    public function test_each_clash_is_answered_with_the_next_number(): void
    {
        $this->registerBarbershop->execute($this->command());
        $this->registerBarbershop->execute($this->command());
        $attempts = $this->barbershops->saveAttempts();

        $third = $this->registerBarbershop->execute($this->command());

        $this->assertSame('pura-navaja-3', $third->link->value);
        $this->assertSame(3, $this->barbershops->saveAttempts() - $attempts);
        $this->assertCount(3, $this->owners->assigned());
    }

    /** When every allowed link is taken the registration fails clearly and leaves nothing behind. */
    public function test_exhausted_attempts_fail_and_roll_back(): void
    {
        for ($i = 0; $i < RegisterBarbershop::MAX_LINK_ATTEMPTS; $i++) {
            $this->registerBarbershop->execute($this->command());
        }

        $registered = count($this->barbershops->all());
        $assigned = count($this->owners->assigned());

        try {
            $this->registerBarbershop->execute($this->command());
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
        $barbershop = $this->registerBarbershop->execute($this->command(name: '  Barbería Pura Navaja '));

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
            $this->registerBarbershop->execute($this->command());
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
            $this->registerBarbershop->execute($this->command($name, $city, $phone));
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
        return new RegisterBarbershopCommand($name, $city, $phone, self::OWNER_ID);
    }
}
