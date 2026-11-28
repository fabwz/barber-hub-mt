<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Infrastructure\Persistence\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use Src\Catalog\RentInstallments\Domain\Contracts\RentInstallmentRepository;
use Src\Catalog\RentInstallments\Domain\Entities\RentInstallment;
use Src\Catalog\RentInstallments\Domain\Services\RentCalendar;
use Src\Catalog\RentInstallments\Domain\ValueObjects\InstallmentStatus;
use Src\Catalog\RentInstallments\Domain\ValueObjects\RentInstallmentId;
use Src\Catalog\RentInstallments\Infrastructure\Persistence\Models\RentInstallmentModel;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Money;

final readonly class EloquentRentInstallmentRepository implements RentInstallmentRepository
{
    /** Generate a time-ordered UUID for a new installment. */
    public function nextId(): RentInstallmentId
    {
        return new RentInstallmentId((string) Str::uuid7());
    }

    /** Insert with ON CONFLICT DO NOTHING on (barbershop_id, due_on): two runs, or two processes, cannot both create the month. */
    public function addIfMissing(RentInstallment $installment): bool
    {
        return RentInstallmentModel::query()->insertOrIgnore([
            'id' => $installment->id->value,
            'barbershop_id' => $installment->barbershopId->value,
            'amount' => $installment->amount->colones,
            'due_on' => $installment->dueOn->format('Y-m-d'),
            'status' => $installment->status->value,
        ]) === 1;
    }

    /** The latest due date of the barbershop. */
    public function latestDueOn(BarbershopId $barbershopId): ?DateTimeImmutable
    {
        $latest = RentInstallmentModel::query()->where('barbershop_id', $barbershopId->value)->max('due_on');

        return $latest === null ? null : new DateTimeImmutable((string) $latest, new DateTimeZone(RentCalendar::ZONE));
    }

    /** The unpaid installments of the barbershop, oldest first. */
    public function unpaidOf(BarbershopId $barbershopId): array
    {
        $models = RentInstallmentModel::query()
            ->where('barbershop_id', $barbershopId->value)
            ->where('status', InstallmentStatus::Pending->value)
            ->orderBy('due_on')
            ->get();

        return array_values($models->map(fn (RentInstallmentModel $model): RentInstallment => RentInstallment::reconstitute(
            new RentInstallmentId($model->id),
            new BarbershopId($model->barbershop_id),
            Money::of($model->amount),
            new DateTimeImmutable($model->due_on->format('Y-m-d'), new DateTimeZone(RentCalendar::ZONE)),
            InstallmentStatus::from($model->status),
        ))->all());
    }
}
