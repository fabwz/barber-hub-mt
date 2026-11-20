<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Persistence\Repositories;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Domain\Contracts\OfferRepository;
use Src\Waitlist\Offers\Domain\Entities\Offer;
use Src\Waitlist\Offers\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferId;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferStatus;
use Src\Waitlist\Offers\Domain\ValueObjects\WaitlistEntryId;
use Src\Waitlist\Offers\Infrastructure\Persistence\Models\WaitlistOfferModel;

final readonly class EloquentOfferRepository implements OfferRepository
{
    /** Instants travel as text with their offset, so they never depend on the time zone of the process. */
    private const string INSTANT_FORMAT = 'Y-m-d H:i:s.uP';

    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /** Generate a time-ordered UUID for a new offer. */
    public function nextId(): OfferId
    {
        return new OfferId((string) Str::uuid7());
    }

    /** Write the offer: a new one is inserted and an existing one changes only its status and its answer instant. */
    public function save(Offer $offer): void
    {
        $model = $this->ofBarbershop()->find($offer->id->value);
        $respondedAt = $offer->respondedAt === null ? null : self::instant($offer->respondedAt);

        if ($model === null) {
            WaitlistOfferModel::query()->create([
                'id' => $offer->id->value,
                'barbershop_id' => $this->context->current()->value,
                'waitlist_entry_id' => $offer->entryId->value,
                'collaborator_id' => $offer->collaboratorId->value,
                'starts_at' => self::instant($offer->interval->start),
                'ends_at' => self::instant($offer->interval->end),
                'expires_at' => self::instant($offer->expiresAt),
                'status' => $offer->status->value,
                'responded_at' => $respondedAt,
                'created_at' => self::instant($offer->createdAt),
            ]);

            return;
        }

        $model->update(['status' => $offer->status->value, 'responded_at' => $respondedAt]);
    }

    /** Find the offer among those of the current barbershop. */
    public function find(OfferId $id): ?Offer
    {
        $model = $this->ofBarbershop()->find($id->value);

        return $model === null ? null : $this->toDomain($model);
    }

    /** Find the offer among those of the current barbershop, locking its row until the transaction ends. */
    public function findForUpdate(OfferId $id): ?Offer
    {
        $model = $this->ofBarbershop()->lockForUpdate()->find($id->value);

        return $model === null ? null : $this->toDomain($model);
    }

    /** Whether an offer of the row, whatever its state, is for the same collaborator and shares some minute with the space. */
    public function hadOfferOverlapping(WaitlistEntryId $entry, CollaboratorId $collaborator, TimeInterval $space): bool
    {
        return $this->ofBarbershop()
            ->where('waitlist_entry_id', $entry->value)
            ->where('collaborator_id', $collaborator->value)
            ->where('starts_at', '<', self::instant($space->end))
            ->where('ends_at', '>', self::instant($space->start))
            ->exists();
    }

    /**
     * Whether a pending offer, still inside its time, of any of the rows shares some minute with the space.
     *
     * @param  list<WaitlistEntryId>  $entries
     */
    public function hasPendingOverlapping(array $entries, TimeInterval $space, DateTimeImmutable $now): bool
    {
        if ($entries === []) {
            return false;
        }

        return $this->ofBarbershop()
            ->whereIn('waitlist_entry_id', array_map(fn (WaitlistEntryId $entry): string => $entry->value, $entries))
            ->where('status', OfferStatus::Pending->value)
            ->where('expires_at', '>', self::instant($now))
            ->where('starts_at', '<', self::instant($space->end))
            ->where('ends_at', '>', self::instant($space->start))
            ->exists();
    }

    /** @return Builder<WaitlistOfferModel> */
    private function ofBarbershop(): Builder
    {
        return WaitlistOfferModel::query()->where('barbershop_id', $this->context->current()->value);
    }

    private function toDomain(WaitlistOfferModel $model): Offer
    {
        return Offer::reconstitute(
            new OfferId($model->id),
            new WaitlistEntryId($model->waitlist_entry_id),
            new CollaboratorId($model->collaborator_id),
            TimeInterval::between(new DateTimeImmutable($model->starts_at), new DateTimeImmutable($model->ends_at)),
            new DateTimeImmutable($model->expires_at),
            OfferStatus::from($model->status),
            $model->responded_at === null ? null : new DateTimeImmutable($model->responded_at),
            new DateTimeImmutable($model->created_at),
        );
    }

    private static function instant(DateTimeImmutable $at): string
    {
        return $at->format(self::INSTANT_FORMAT);
    }
}
