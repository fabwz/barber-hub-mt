<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Persistence\Repositories;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\PurchasedVoucher;
use Src\Vouchers\PurchasedVouchers\Domain\Entities\VoucherMovement;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\InsufficientBalanceException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherExpiredException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotActiveException;
use Src\Vouchers\PurchasedVouchers\Domain\Exceptions\VoucherNotFoundException;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\CustomerMembershipId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\PurchasedVoucherId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\ReservationId;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\SessionBalance;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\VoucherMovementType;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\VoucherStatus;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Persistence\Models\PurchasedVoucherModel;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Persistence\Models\VoucherMovementModel;
use Src\Vouchers\Shared\Domain\ValueObjects\SessionCount;
use Src\Vouchers\Shared\Domain\ValueObjects\ValidityDays;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherName;

final readonly class EloquentPurchasedVoucherRepository implements PurchasedVoucherRepository
{
    /** Instants travel as text with their offset, so they never depend on the time zone of the process. */
    private const string INSTANT_FORMAT = 'Y-m-d H:i:s.uP';

    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /** Generate a time-ordered UUID for a new purchased voucher. */
    public function nextId(): PurchasedVoucherId
    {
        return new PurchasedVoucherId((string) Str::uuid7());
    }

    /** Write the voucher and append its new entries to the record, in one transaction (a savepoint inside the one of the request). */
    public function save(PurchasedVoucher $voucher): void
    {
        DB::transaction(function () use ($voucher): void {
            $barbershop = $this->context->current()->value;
            $values = [
                'status' => $voucher->status->value,
                'activated_at' => $voucher->activatedAt === null ? null : self::instant($voucher->activatedAt),
                'expires_at' => $voucher->expiresAt === null ? null : self::instant($voucher->expiresAt),
                'remaining_sessions' => $voucher->balance->value,
                'updated_at' => self::instant($voucher->updatedAt),
            ];
            $model = $this->ofBarbershop()->find($voucher->id->value);

            if ($model === null) {
                PurchasedVoucherModel::query()->create([
                    'id' => $voucher->id->value,
                    'barbershop_id' => $barbershop,
                    'customer_membership_id' => $voucher->customerMembershipId->value,
                    'voucher_definition_id' => $voucher->voucherDefinitionId->value,
                    'name' => $voucher->name->value,
                    'price' => $voucher->price->colones,
                    'sessions_count' => $voucher->sessionsCount->value,
                    'validity_days' => $voucher->validityDays->value,
                    'created_at' => self::instant($voucher->createdAt),
                    ...$values,
                ]);
            } else {
                $model->update($values);
            }

            foreach ($voucher->pullMovements() as $movement) {
                $this->record($voucher->id, $barbershop, $movement->type, $movement->sessionsDelta, $movement->reservationId, $movement->at);
            }
        });
    }

    /** Find the voucher among those of the current barbershop. */
    public function find(PurchasedVoucherId $id): ?PurchasedVoucher
    {
        $model = $this->ofBarbershop()->find($id->value);

        return $model === null ? null : $this->toDomain($model);
    }

    /** Find the voucher among those of the current barbershop, locking its row until the transaction ends. */
    public function findForUpdate(PurchasedVoucherId $id): ?PurchasedVoucher
    {
        $model = $this->ofBarbershop()->lockForUpdate()->find($id->value);

        return $model === null ? null : $this->toDomain($model);
    }

    /**
     * Read the record of the voucher in the order it was written. The identifiers are time-ordered UUIDs, so they break the
     * tie between two entries of the same instant.
     *
     * @return list<VoucherMovement>
     */
    public function movementsOf(PurchasedVoucherId $id): array
    {
        $rows = VoucherMovementModel::query()
            ->where('barbershop_id', $this->context->current()->value)
            ->where('purchased_voucher_id', $id->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return array_values($rows->map(fn (VoucherMovementModel $row): VoucherMovement => VoucherMovement::reconstitute(
            VoucherMovementType::from($row->type),
            $row->sessions_delta,
            $row->reservation_id === null ? null : new ReservationId($row->reservation_id),
            new DateTimeImmutable($row->created_at),
        ))->all());
    }

    /**
     * Take the session with one conditional UPDATE: the row only matches while the voucher is active, valid at the instant and
     * has a session left. PostgreSQL locks the row for the first caller and, when the second one gets the lock, checks the
     * condition again on the committed row, so the last session goes to exactly one caller and the check of the table is a
     * second wall. Nothing is read before deciding. When no row matched, the voucher is read only to tell why. The entry of the
     * record is written in the same transaction, so a refused session leaves nothing behind.
     */
    public function holdSession(PurchasedVoucherId $id, ReservationId $reservation, DateTimeImmutable $at): void
    {
        DB::transaction(function () use ($id, $reservation, $at): void {
            $instant = self::instant($at);
            $taken = $this->ofBarbershop()
                ->whereKey($id->value)
                ->where('status', VoucherStatus::Active->value)
                ->where('remaining_sessions', '>', 0)
                ->where('expires_at', '>', $instant)
                ->update(['remaining_sessions' => DB::raw('remaining_sessions - 1'), 'updated_at' => $instant]);

            if ($taken === 0) {
                throw $this->whyNotHeld($id, $instant);
            }

            $this->record($id, $this->context->current()->value, VoucherMovementType::Hold, VoucherMovementType::Hold->delta(), $reservation, $at);
        });
    }

    /** Tell why the update matched nothing: the voucher is not there, is not paid, has expired or has no session left. */
    private function whyNotHeld(PurchasedVoucherId $id, string $instant): VoucherNotFoundException|VoucherNotActiveException|VoucherExpiredException|InsufficientBalanceException
    {
        $voucher = $this->ofBarbershop()->find($id->value);

        return match (true) {
            $voucher === null => VoucherNotFoundException::create(),
            $voucher->status !== VoucherStatus::Active->value => VoucherNotActiveException::create(),
            $voucher->expires_at === null || new DateTimeImmutable($voucher->expires_at) <= new DateTimeImmutable($instant) => VoucherExpiredException::create(),
            default => InsufficientBalanceException::create(),
        };
    }

    /** Append an entry to the record. */
    private function record(PurchasedVoucherId $voucher, string $barbershop, VoucherMovementType $type, int $delta, ?ReservationId $reservation, DateTimeImmutable $at): void
    {
        VoucherMovementModel::query()->create([
            'id' => (string) Str::uuid7(),
            'barbershop_id' => $barbershop,
            'purchased_voucher_id' => $voucher->value,
            'reservation_id' => $reservation?->value,
            'type' => $type->value,
            'sessions_delta' => $delta,
            'created_at' => self::instant($at),
        ]);
    }

    /**
     * The vouchers of the current barbershop: no query of this repository leaves it; the row-level policy is the second barrier.
     *
     * @return Builder<PurchasedVoucherModel>
     */
    private function ofBarbershop(): Builder
    {
        return PurchasedVoucherModel::query()->where('barbershop_id', $this->context->current()->value);
    }

    /** Rebuild the aggregate from its stored state. */
    private function toDomain(PurchasedVoucherModel $model): PurchasedVoucher
    {
        return PurchasedVoucher::reconstitute(
            new PurchasedVoucherId($model->id),
            new BarbershopId($model->barbershop_id),
            new CustomerMembershipId($model->customer_membership_id),
            new VoucherDefinitionId($model->voucher_definition_id),
            VoucherName::of($model->name),
            Money::of($model->price),
            SessionCount::of($model->sessions_count),
            ValidityDays::of($model->validity_days),
            VoucherStatus::from($model->status),
            $model->activated_at === null ? null : new DateTimeImmutable($model->activated_at),
            $model->expires_at === null ? null : new DateTimeImmutable($model->expires_at),
            SessionBalance::of($model->remaining_sessions),
            new DateTimeImmutable($model->created_at),
            new DateTimeImmutable($model->updated_at),
        );
    }

    private static function instant(DateTimeImmutable $at): string
    {
        return $at->format(self::INSTANT_FORMAT);
    }
}
