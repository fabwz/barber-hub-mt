<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Console;

use Illuminate\Console\Command;
use Src\Identity\Users\Application\DTOs\RegisterPlatformOperatorCommand;
use Src\Identity\Users\Application\UseCases\RegisterPlatformOperator;
use Src\Identity\Users\Domain\Exceptions\EmailAlreadyRegisteredException;
use Src\Shared\Domain\Exceptions\InvalidValueException;

/**
 * Creates the account of a platform operator (RF-55, PL-01): the only way to make one, since no route of the API can. Unlike the demo
 * seeder it runs in production on purpose. The temporary password is asked for (hidden) or generated, is shown once on the terminal and is
 * never written anywhere: not to the log, not to a file. The operator must change it at the first login (RF-40).
 */
final class CreatePlatformOperatorCommand extends Command
{
    protected $signature = 'platform:create-operator
        {email : Email of the new account, which is also its login}
        {name : Full name of the operator}';

    protected $description = 'Create a platform operator account with a temporary password (shown once)';

    /** Create the account and show its temporary password. */
    public function handle(RegisterPlatformOperator $registerPlatformOperator): int
    {
        $typed = $this->input->isInteractive() ? (string) $this->secret('Temporary password (leave empty to generate one)') : '';
        $generated = $typed === '';
        $password = $generated ? $this->generatePassword() : $typed;

        try {
            $user = $registerPlatformOperator->execute(new RegisterPlatformOperatorCommand((string) $this->argument('name'), (string) $this->argument('email'), $password));
        } catch (EmailAlreadyRegisteredException) {
            $this->components->error('That email already has an account. An existing account is never turned into an operator.');

            return self::FAILURE;
        } catch (InvalidValueException $exception) {
            $this->components->error('A value is not valid: '.__($exception->translationKey(), $exception->parameters()));

            return self::FAILURE;
        }

        $this->components->info("Platform operator created: {$user->email->value}");

        if ($generated) {
            $this->components->warn('Temporary password (shown only now, copy it and hand it over safely):');
            $this->line($password);
        }

        $this->components->info('The operator must change the password at the first login.');

        return self::SUCCESS;
    }

    /** A random password of 16 characters that meets the password policy (SE-07): one of each kind and the rest from all of them. */
    private function generatePassword(): string
    {
        $kinds = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '!#$%&*+-=?@^_'];
        $characters = array_map(fn (string $kind): string => $kind[random_int(0, strlen($kind) - 1)], $kinds);
        $all = implode('', $kinds);

        while (count($characters) < 16) {
            $characters[] = $all[random_int(0, strlen($all) - 1)];
        }

        for ($index = count($characters) - 1; $index > 0; $index--) {
            $other = random_int(0, $index);
            [$characters[$index], $characters[$other]] = [$characters[$other], $characters[$index]];
        }

        return implode('', $characters);
    }
}
