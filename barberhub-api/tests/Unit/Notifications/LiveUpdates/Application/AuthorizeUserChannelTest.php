<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\LiveUpdates\Application;

use PHPUnit\Framework\TestCase;
use Src\Notifications\LiveUpdates\Application\Exceptions\ChannelNotFoundException;
use Src\Notifications\LiveUpdates\Application\UseCases\AuthorizeUserChannel;
use Src\Notifications\LiveUpdates\Application\UserChannels;

/** The rule of the channel of a person, without the framework: only the owner of the account listens, and anyone else reads a missing channel (RF-35, UP-06). */
final class AuthorizeUserChannelTest extends TestCase
{
    private const string ANA = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d21';

    private const string LUIS = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d22';

    /** A person is let into their own channel, in any case of the letters. */
    public function test_a_person_listens_to_their_own_channel(): void
    {
        $rule = new AuthorizeUserChannel;

        $rule->execute(self::ANA, self::ANA);
        $rule->execute(self::ANA, strtoupper(self::ANA));

        $this->addToAssertionCount(1);
    }

    /** The channel of another person and a name that is no identifier are the same not found. */
    public function test_another_person_or_a_bad_name_is_not_found(): void
    {
        foreach ([self::LUIS, 'no-es-un-uuid', ''] as $channel) {
            try {
                (new AuthorizeUserChannel)->execute(self::ANA, $channel);
                $this->fail("Let in to {$channel}");
            } catch (ChannelNotFoundException $exception) {
                $this->assertSame('problems.no-encontrado.detail', $exception->getMessage());
            }
        }
    }

    /** The name of the channel is `usuario.` and the account. */
    public function test_the_channel_is_named_after_the_account(): void
    {
        $this->assertSame('usuario.'.self::ANA, UserChannels::ofUser(self::ANA));
    }
}
