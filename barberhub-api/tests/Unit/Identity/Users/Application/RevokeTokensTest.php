<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Users\Application;

use PHPUnit\Framework\TestCase;
use Src\Identity\Users\Application\UseCases\RevokeAllTokens;
use Src\Identity\Users\Application\UseCases\RevokeCurrentToken;
use Tests\Support\Identity\FakeTokenRevoker;

final class RevokeTokensTest extends TestCase
{
    private const string USER_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d11';

    /** Closing the session of a device revokes only that token of the user. */
    public function test_revokes_the_current_token_of_the_user(): void
    {
        $tokens = new FakeTokenRevoker;

        new RevokeCurrentToken($tokens)->execute(self::USER_ID, '7');

        $this->assertSame([[self::USER_ID, '7']], $tokens->revoked);
        $this->assertSame([], $tokens->revokedAll);
    }

    /** Closing every session revokes all the tokens of the user. */
    public function test_revokes_every_token_of_the_user(): void
    {
        $tokens = new FakeTokenRevoker;

        new RevokeAllTokens($tokens)->execute(self::USER_ID);

        $this->assertSame([self::USER_ID], $tokens->revokedAll);
        $this->assertSame([], $tokens->revoked);
    }
}
