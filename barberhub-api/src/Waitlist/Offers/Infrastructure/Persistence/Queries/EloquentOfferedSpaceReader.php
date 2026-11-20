<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Persistence\Queries;

use DateTimeImmutable;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Application\Contracts\OfferedSpaceReader;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferStatus;
use Src\Waitlist\Offers\Infrastructure\Persistence\Models\WaitlistOfferModel;

final readonly class EloquentOfferedSpaceReader implements OfferedSpaceReader
{
    /** Instants travel as text with their offset, so they never depend on the time zone of the process. */
    private const string INSTANT_FORMAT = 'Y-m-d H:i:s.uP';

    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /**
     * Read the spaces of the pending offers of some collaborators that overlap a window. An offer counts only while its 15 minutes are
     * open at `$now`, even if its task has not marked it expired yet (DI-05, LE-05).
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>>
     */
    public function between(array $collaboratorIds, TimeInterval $window, DateTimeImmutable $now): array
    {
        $rows = WaitlistOfferModel::query()
            ->where('barbershop_id', $this->context->current()->value)
            ->whereIn('collaborator_id', $collaboratorIds)
            ->where('status', OfferStatus::Pending->value)
            ->where('expires_at', '>', $now->format(self::INSTANT_FORMAT))
            ->where('starts_at', '<', $window->end->format(self::INSTANT_FORMAT))
            ->where('ends_at', '>', $window->start->format(self::INSTANT_FORMAT))
            ->orderBy('starts_at')
            ->get(['collaborator_id', 'starts_at', 'ends_at']);

        $spaces = [];

        foreach ($rows as $row) {
            $spaces[$row->collaborator_id][] = TimeInterval::between(new DateTimeImmutable($row->starts_at), new DateTimeImmutable($row->ends_at));
        }

        return $spaces;
    }
}
