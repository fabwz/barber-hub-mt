<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;

/** What became of a verified notice; the caller logs it and answers 200 in every case. */
final readonly class NoticeResult
{
    public const string APPLIED = 'applied';

    public const string DUPLICATE = 'duplicate';

    public const string IGNORED = 'ignored';

    public const string UNKNOWN_PAYMENT = 'unknown_payment';

    public function __construct(public PaymentProvider $provider, public ?string $eventId, public string $outcome) {}
}
