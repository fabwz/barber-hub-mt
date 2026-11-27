<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Infrastructure\Persistence\Repositories;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Catalog\Invitations\Domain\Contracts\InvitationRepository;
use Src\Catalog\Invitations\Domain\Entities\Invitation;
use Src\Catalog\Invitations\Domain\ValueObjects\InvitationId;
use Src\Catalog\Invitations\Infrastructure\Persistence\Models\InvitationModel;
use Src\Catalog\Invitations\Infrastructure\Persistence\Models\InvitationServiceModel;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class EloquentInvitationRepository implements InvitationRepository
{
    private const string INSTANT = 'Y-m-d H:i:s.uP';

    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /** Generate a time-ordered UUID for a new invitation. */
    public function nextId(): InvitationId
    {
        return new InvitationId((string) Str::uuid7());
    }

    /** Write the invitation and its services over the pending ones of the same email, in one transaction. */
    public function replacePending(Invitation $invitation, string $invitedByMembershipId, string $tokenHash): void
    {
        $barbershopId = $this->context->current()->value;

        DB::transaction(function () use ($invitation, $invitedByMembershipId, $tokenHash, $barbershopId): void {
            InvitationModel::query()
                ->where('barbershop_id', $barbershopId)
                ->where('email', $invitation->email)
                ->whereNull('used_at')
                ->delete();

            InvitationModel::query()->insert([
                'id' => $invitation->id->value,
                'barbershop_id' => $barbershopId,
                'email' => $invitation->email,
                'token_hash' => $tokenHash,
                'expires_at' => $invitation->expiresAt->format(self::INSTANT),
                'invited_by_membership_id' => $invitedByMembershipId,
                'created_at' => $invitation->createdAt->format(self::INSTANT),
            ]);

            InvitationServiceModel::query()->insert(array_map(fn (string $serviceId): array => [
                'id' => (string) Str::uuid7(),
                'barbershop_id' => $barbershopId,
                'invitation_id' => $invitation->id->value,
                'service_id' => $serviceId,
            ], $invitation->serviceIds));
        });
    }

    /**
     * The pending invitations of the current barbershop, the newest first.
     *
     * @return list<Invitation>
     */
    public function pending(DateTimeImmutable $now): array
    {
        $models = $this->pendingQuery($now)->orderByDesc('created_at')->orderByDesc('id')->get();

        return $this->hydrate($models->all());
    }

    /** Delete the pending invitation; its services go with it. */
    public function cancel(InvitationId $id, DateTimeImmutable $now): bool
    {
        return $this->pendingQuery($now)->whereKey($id->value)->delete() > 0;
    }

    /** Replace the hash of the link of a pending invitation, and read it back. */
    public function replaceLink(InvitationId $id, string $tokenHash, DateTimeImmutable $now): ?Invitation
    {
        $updated = $this->pendingQuery($now)->whereKey($id->value)->update(['token_hash' => $tokenHash]);

        return $updated === 0 ? null : $this->hydrate($this->pendingQuery($now)->whereKey($id->value)->get()->all())[0] ?? null;
    }

    /** Find the pending invitation of a link; the hash read back is compared with `hash_equals`, whatever the database did to find it. */
    public function findPendingByLink(string $tokenHash, DateTimeImmutable $now): ?Invitation
    {
        $model = $this->pendingQuery($now)->where('token_hash', $tokenHash)->first();

        if ($model === null || ! hash_equals($model->token_hash, $tokenHash)) {
            return null;
        }

        return $this->hydrate([$model])[0];
    }

    /**
     * Mark the pending invitation of a link as used and answer it in one statement, so a second request with the same link finds nothing.
     * Inside the transaction of the use case, so what follows rolls it back if it fails.
     */
    public function consume(string $tokenHash, DateTimeImmutable $now): ?Invitation
    {
        $rows = (new InvitationModel)->getConnection()->select(
            'UPDATE invitations SET used_at = ? WHERE barbershop_id = ? AND token_hash = ? AND used_at IS NULL AND expires_at > ? RETURNING id, token_hash',
            [$now->format(self::INSTANT), $this->context->current()->value, $tokenHash, $now->format(self::INSTANT)],
        );

        $row = $rows[0] ?? null;

        if ($row === null || ! hash_equals((string) $row->token_hash, $tokenHash)) {
            return null;
        }

        $model = InvitationModel::query()->where('barbershop_id', $this->context->current()->value)->find((string) $row->id);

        return $model === null ? null : $this->hydrate([$model])[0];
    }

    /**
     * The invitations of the current barbershop that are neither used nor expired at the instant.
     *
     * @return Builder<InvitationModel>
     */
    private function pendingQuery(DateTimeImmutable $now): Builder
    {
        return InvitationModel::query()
            ->where('barbershop_id', $this->context->current()->value)
            ->whereNull('used_at')
            ->where('expires_at', '>', $now->format(self::INSTANT));
    }

    /**
     * Build the invitations of the models with their services, read together.
     *
     * @param  array<int, InvitationModel>  $models
     * @return list<Invitation>
     */
    private function hydrate(array $models): array
    {
        if ($models === []) {
            return [];
        }

        $services = InvitationServiceModel::query()
            ->where('barbershop_id', $this->context->current()->value)
            ->whereIn('invitation_id', array_map(fn (InvitationModel $model): string => $model->id, $models))
            ->orderBy('id')
            ->get()
            ->groupBy('invitation_id');

        return array_values(array_map(fn (InvitationModel $model): Invitation => Invitation::reconstitute(
            new InvitationId($model->id),
            $model->email,
            array_values($services->get($model->id, collect())->map(fn (InvitationServiceModel $service): string => $service->service_id)->all()),
            $model->created_at,
            $model->expires_at,
        ), $models));
    }
}
