<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Infrastructure\Persistence\Repositories;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Waitlist\Entries\Domain\Contracts\WaitlistEntryRepository;
use Src\Waitlist\Entries\Domain\Entities\WaitlistEntry;
use Src\Waitlist\Entries\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Entries\Domain\ValueObjects\CustomerMembershipId;
use Src\Waitlist\Entries\Domain\ValueObjects\DesiredDate;
use Src\Waitlist\Entries\Domain\ValueObjects\ServiceId;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistEntryId;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistStatus;
use Src\Waitlist\Entries\Infrastructure\Persistence\Models\WaitlistEntryModel;

final readonly class EloquentWaitlistEntryRepository implements WaitlistEntryRepository
{
    /** Instants travel as text with their offset, so they never depend on the time zone of the process. */
    private const string INSTANT_FORMAT = 'Y-m-d H:i:s.uP';

    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /** Generate a time-ordered UUID for a new row. */
    public function nextId(): WaitlistEntryId
    {
        return new WaitlistEntryId((string) Str::uuid7());
    }

    /** Write the row: a new one is inserted and an existing one changes only its status and its update instant. */
    public function save(WaitlistEntry $entry): void
    {
        $model = $this->ofBarbershop()->find($entry->id->value);

        if ($model === null) {
            WaitlistEntryModel::query()->create([
                'id' => $entry->id->value,
                'barbershop_id' => $this->context->current()->value,
                'customer_membership_id' => $entry->customerMembershipId->value,
                'service_id' => $entry->serviceId->value,
                'collaborator_id' => $entry->collaboratorId?->value,
                'desired_date' => $entry->desiredDate->value,
                'status' => $entry->status->value,
                'joined_at' => self::instant($entry->joinedAt),
                'created_at' => self::instant($entry->createdAt),
                'updated_at' => self::instant($entry->updatedAt),
            ]);

            return;
        }

        $model->update(['status' => $entry->status->value, 'updated_at' => self::instant($entry->updatedAt)]);
    }

    /**
     * Take a transaction-level advisory lock named after the customer. It is a lock of the line of the customer, not of a row, because
     * the rows that a new one must not exceed may not exist yet: two requests of the same customer wait for each other here.
     */
    public function lockCustomer(CustomerMembershipId $customer): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['waitlist:'.$this->context->current()->value.':'.$customer->value]);
    }

    /** Count the waiting and offered rows of the customer among those of the current barbershop. */
    public function countActiveOfCustomer(CustomerMembershipId $customer): int
    {
        return $this->activeOf($customer)->count();
    }

    /** Whether an active row of the customer has the same service, day and collaborator (null matches only null). */
    public function hasActiveFor(CustomerMembershipId $customer, ServiceId $service, ?CollaboratorId $collaborator, DesiredDate $date): bool
    {
        $query = $this->activeOf($customer)->where('service_id', $service->value)->where('desired_date', $date->value);

        return ($collaborator === null ? $query->whereNull('collaborator_id') : $query->where('collaborator_id', $collaborator->value))->exists();
    }

    /** Find the row among those of the current barbershop, without locking it. */
    public function find(WaitlistEntryId $id): ?WaitlistEntry
    {
        $model = $this->ofBarbershop()->find($id->value);

        return $model === null ? null : $this->toDomain($model);
    }

    /** Find the row among those of the customer in the current barbershop, locking it until the transaction ends. */
    public function findOfCustomerForUpdate(WaitlistEntryId $id, CustomerMembershipId $customer): ?WaitlistEntry
    {
        $model = $this->ofBarbershop()->where('customer_membership_id', $customer->value)->lockForUpdate()->find($id->value);

        return $model === null ? null : $this->toDomain($model);
    }

    /** Find the row among those of the current barbershop, locking it until the transaction ends. */
    public function findForUpdate(WaitlistEntryId $id): ?WaitlistEntry
    {
        $model = $this->ofBarbershop()->lockForUpdate()->find($id->value);

        return $model === null ? null : $this->toDomain($model);
    }

    /**
     * Read the waiting rows of a day that ask for the collaborator or for any, in the order they joined.
     *
     * @return list<WaitlistEntry>
     */
    public function waitingFor(DesiredDate $date, CollaboratorId $collaborator): array
    {
        $models = $this->ofBarbershop()
            ->where('status', WaitlistStatus::Waiting->value)
            ->where('desired_date', $date->value)
            ->where(fn (Builder $asks): Builder => $asks->whereNull('collaborator_id')->orWhere('collaborator_id', $collaborator->value))
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();

        return array_values($models->map(fn (WaitlistEntryModel $model): WaitlistEntry => $this->toDomain($model))->all());
    }

    /**
     * Read the active rows of the customer in the order they joined; the identifier breaks the tie between two of the same instant.
     *
     * @return list<WaitlistEntry>
     */
    public function activeOfCustomer(CustomerMembershipId $customer): array
    {
        $models = $this->activeOf($customer)->orderBy('joined_at')->orderBy('id')->get();

        return array_values($models->map(fn (WaitlistEntryModel $model): WaitlistEntry => $this->toDomain($model))->all());
    }

    /** @return Builder<WaitlistEntryModel> */
    private function ofBarbershop(): Builder
    {
        return WaitlistEntryModel::query()->where('barbershop_id', $this->context->current()->value);
    }

    /** @return Builder<WaitlistEntryModel> */
    private function activeOf(CustomerMembershipId $customer): Builder
    {
        return $this->ofBarbershop()
            ->where('customer_membership_id', $customer->value)
            ->whereIn('status', [WaitlistStatus::Waiting->value, WaitlistStatus::Offered->value]);
    }

    private function toDomain(WaitlistEntryModel $model): WaitlistEntry
    {
        return WaitlistEntry::reconstitute(
            new WaitlistEntryId($model->id),
            new CustomerMembershipId($model->customer_membership_id),
            new ServiceId($model->service_id),
            $model->collaborator_id === null ? null : new CollaboratorId($model->collaborator_id),
            DesiredDate::of($model->desired_date),
            WaitlistStatus::from($model->status),
            new DateTimeImmutable($model->joined_at),
            new DateTimeImmutable($model->created_at),
            new DateTimeImmutable($model->updated_at),
        );
    }

    private static function instant(DateTimeImmutable $at): string
    {
        return $at->format(self::INSTANT_FORMAT);
    }
}
