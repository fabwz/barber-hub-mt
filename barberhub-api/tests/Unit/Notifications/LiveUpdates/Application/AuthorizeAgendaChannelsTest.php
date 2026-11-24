<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\LiveUpdates\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Notifications\LiveUpdates\Application\Contracts\AgendaCollaborators;
use Src\Notifications\LiveUpdates\Application\Exceptions\ChannelForbiddenException;
use Src\Notifications\LiveUpdates\Application\Exceptions\ChannelNotFoundException;
use Src\Notifications\LiveUpdates\Application\UseCases\AuthorizeAgendaChannel;
use Src\Notifications\LiveUpdates\Application\UseCases\AuthorizeCollaboratorChannel;
use Src\Shared\Domain\Contracts\BarbershopRoles;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;
use Throwable;

/** The rule of the two channels of the live agenda, without the framework: who is told yes, who not found and who forbidden (RF-36, CO-04, UP-02, UP-06). */
final class AuthorizeAgendaChannelsTest extends TestCase
{
    public const string BARBERSHOP = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d01';

    public const string OTHER_BARBERSHOP = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d02';

    public const string OWNER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d11';

    public const string DANIELA_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d12';

    public const string KEVIN_USER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d13';

    public const string ANA = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d14';

    public const string STRANGER = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d15';

    public const string DANIELA = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d55';

    public const string KEVIN = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d56';

    /** The roles of the people in the barbershop, and the profile of each collaborator. */
    private function roles(): BarbershopRoles
    {
        return new class implements BarbershopRoles
        {
            public function roleOf(UserId $userId, BarbershopId $barbershopId): ?string
            {
                if ($barbershopId->value !== AuthorizeAgendaChannelsTest::BARBERSHOP) {
                    return null;
                }

                return [
                    AuthorizeAgendaChannelsTest::OWNER => 'owner',
                    AuthorizeAgendaChannelsTest::DANIELA_USER => 'collaborator',
                    AuthorizeAgendaChannelsTest::KEVIN_USER => 'collaborator',
                    AuthorizeAgendaChannelsTest::ANA => 'customer',
                ][$userId->value] ?? null;
            }
        };
    }

    private function collaborators(): AgendaCollaborators
    {
        return new class implements AgendaCollaborators
        {
            public function profileOf(string $userId, string $barbershopId): ?string
            {
                return [
                    AuthorizeAgendaChannelsTest::DANIELA_USER => AuthorizeAgendaChannelsTest::DANIELA,
                    AuthorizeAgendaChannelsTest::KEVIN_USER => AuthorizeAgendaChannelsTest::KEVIN,
                ][$userId] ?? null;
            }
        };
    }

    /**
     * Who gets what in the channel of the agenda of the barbershop: only the owner.
     *
     * @return array<string, array{string, string, class-string<Throwable>|null}>
     */
    public static function agendaCases(): array
    {
        return [
            'the owner' => [self::OWNER, self::BARBERSHOP, null],
            'the owner, with capitals' => [self::OWNER, strtoupper(self::BARBERSHOP), null],
            'a collaborator' => [self::DANIELA_USER, self::BARBERSHOP, ChannelForbiddenException::class],
            'a customer' => [self::ANA, self::BARBERSHOP, ChannelForbiddenException::class],
            'a person without a role' => [self::STRANGER, self::BARBERSHOP, ChannelNotFoundException::class],
            'the owner in another barbershop' => [self::OWNER, self::OTHER_BARBERSHOP, ChannelNotFoundException::class],
            'a barbershop that is not an identifier' => [self::OWNER, 'no-es-un-uuid', ChannelNotFoundException::class],
        ];
    }

    /** @param  class-string<Throwable>|null  $refusal */
    #[DataProvider('agendaCases')]
    public function test_the_agenda_of_the_barbershop_is_for_its_owner(string $user, string $barbershop, ?string $refusal): void
    {
        $useCase = new AuthorizeAgendaChannel($this->roles());

        if ($refusal !== null) {
            $this->expectException($refusal);
        }

        $useCase->execute($user, $barbershop);

        $this->addToAssertionCount(1);
    }

    /**
     * Who gets what in the channel of Daniela: only Daniela.
     *
     * @return array<string, array{string, string, string, class-string<Throwable>|null}>
     */
    public static function collaboratorCases(): array
    {
        return [
            'the collaborator of the agenda' => [self::DANIELA_USER, self::BARBERSHOP, self::DANIELA, null],
            'the collaborator, with capitals' => [self::DANIELA_USER, self::BARBERSHOP, strtoupper(self::DANIELA), null],
            'a colleague' => [self::KEVIN_USER, self::BARBERSHOP, self::DANIELA, ChannelNotFoundException::class],
            'a collaborator asking for a profile that is not theirs and does not exist' => [self::KEVIN_USER, self::BARBERSHOP, '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d99', ChannelNotFoundException::class],
            'a collaborator asking for something that is not an identifier' => [self::DANIELA_USER, self::BARBERSHOP, 'no-es-un-uuid', ChannelNotFoundException::class],
            'the owner, who hears the whole agenda elsewhere' => [self::OWNER, self::BARBERSHOP, self::DANIELA, ChannelForbiddenException::class],
            'a customer' => [self::ANA, self::BARBERSHOP, self::DANIELA, ChannelForbiddenException::class],
            'a person without a role' => [self::STRANGER, self::BARBERSHOP, self::DANIELA, ChannelNotFoundException::class],
            'the collaborator in another barbershop' => [self::DANIELA_USER, self::OTHER_BARBERSHOP, self::DANIELA, ChannelNotFoundException::class],
            'a barbershop that is not an identifier' => [self::DANIELA_USER, 'no-es-un-uuid', self::DANIELA, ChannelNotFoundException::class],
        ];
    }

    /** @param  class-string<Throwable>|null  $refusal */
    #[DataProvider('collaboratorCases')]
    public function test_the_agenda_of_a_collaborator_is_for_that_collaborator(string $user, string $barbershop, string $collaborator, ?string $refusal): void
    {
        $useCase = new AuthorizeCollaboratorChannel($this->roles(), $this->collaborators());

        if ($refusal !== null) {
            $this->expectException($refusal);
        }

        $useCase->execute($user, $barbershop, $collaborator);

        $this->addToAssertionCount(1);
    }
}
