<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Application\DTOs\RequestPasswordResetCommand;
use Src\Identity\Users\Domain\Contracts\PasswordResetLimiter;
use Src\Identity\Users\Domain\Events\PasswordResetRequested;
use Src\Identity\Users\Domain\Exceptions\TooManyPasswordResetRequestsException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Shared\Domain\Contracts\DomainEvents;

final readonly class RequestPasswordReset
{
    /** Receive the limiter of the requests and the port that announces the facts of the domain. */
    public function __construct(
        private PasswordResetLimiter $limiter,
        private DomainEvents $events,
    ) {}

    /**
     * Accept a request for the link (RF-41). It never looks for the account: it counts the request (5 per hour per email and per address, SE-06)
     * and announces it, so the work and the answer are the same whether or not the email has an account. Who sends the link finds out later, in the queue.
     *
     * @throws TooManyPasswordResetRequestsException
     */
    public function execute(RequestPasswordResetCommand $command): void
    {
        $email = new Email($command->email);

        $wait = $this->limiter->hit($email, $command->ipAddress);

        if ($wait > 0) {
            throw TooManyPasswordResetRequestsException::create($wait);
        }

        $this->events->publish(new PasswordResetRequested($email->value));
    }
}
