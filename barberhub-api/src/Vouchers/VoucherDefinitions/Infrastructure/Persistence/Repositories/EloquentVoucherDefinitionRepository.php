<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Infrastructure\Persistence\Repositories;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Vouchers\Shared\Domain\ValueObjects\SessionCount;
use Src\Vouchers\Shared\Domain\ValueObjects\ValidityDays;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherName;
use Src\Vouchers\VoucherDefinitions\Domain\Contracts\VoucherDefinitionRepository;
use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\CoveredServices;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\ServiceId;
use Src\Vouchers\VoucherDefinitions\Infrastructure\Persistence\Models\VoucherDefinitionModel;
use Src\Vouchers\VoucherDefinitions\Infrastructure\Persistence\Models\VoucherDefinitionServiceModel;

final readonly class EloquentVoucherDefinitionRepository implements VoucherDefinitionRepository
{
    /** Instants travel as text with their offset, so they never depend on the time zone of the process. */
    private const string INSTANT_FORMAT = 'Y-m-d H:i:s.uP';

    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /** Generate a time-ordered UUID for a new voucher definition. */
    public function nextId(): VoucherDefinitionId
    {
        return new VoucherDefinitionId((string) Str::uuid7());
    }

    /** Write the definition and bring the services it covers to the ones it has now, in one transaction (a savepoint inside the one of the request). */
    public function save(VoucherDefinition $definition): void
    {
        DB::transaction(function () use ($definition): void {
            $barbershop = $this->context->current()->value;
            $values = [
                'name' => $definition->name->value,
                'sessions_count' => $definition->sessionsCount->value,
                'price' => $definition->price->colones,
                'validity_days' => $definition->validityDays->value,
                'is_active' => $definition->isActive,
                'updated_at' => $definition->updatedAt->format(self::INSTANT_FORMAT),
            ];
            $model = $this->ofBarbershop()->find($definition->id->value);

            if ($model === null) {
                VoucherDefinitionModel::query()->create([
                    'id' => $definition->id->value,
                    'barbershop_id' => $barbershop,
                    'created_at' => $definition->createdAt->format(self::INSTANT_FORMAT),
                    ...$values,
                ]);
            } else {
                $model->update($values);
            }

            $this->syncServices($definition, $barbershop);
        });
    }

    /** Find the definition among those of the current barbershop, with its services. */
    public function find(VoucherDefinitionId $id): ?VoucherDefinition
    {
        $model = $this->ofBarbershop()->find($id->value);

        if ($model === null) {
            return null;
        }

        $services = VoucherDefinitionServiceModel::query()
            ->where('barbershop_id', $this->context->current()->value)
            ->where('voucher_definition_id', $id->value)
            ->orderBy('id')
            ->pluck('service_id')
            ->all();

        return VoucherDefinition::reconstitute(
            new VoucherDefinitionId($model->id),
            new BarbershopId($model->barbershop_id),
            VoucherName::of($model->name),
            SessionCount::of($model->sessions_count),
            Money::of($model->price),
            ValidityDays::of($model->validity_days),
            CoveredServices::of(array_values(array_map(fn (string $service): ServiceId => new ServiceId($service), $services))),
            $model->is_active,
            new DateTimeImmutable($model->created_at),
            new DateTimeImmutable($model->updated_at),
        );
    }

    /** Delete the services the voucher no longer covers and add the new ones. */
    private function syncServices(VoucherDefinition $definition, string $barbershop): void
    {
        $wanted = array_map(fn (ServiceId $service): string => $service->value, $definition->services->ids);
        $query = VoucherDefinitionServiceModel::query()
            ->where('barbershop_id', $barbershop)
            ->where('voucher_definition_id', $definition->id->value);
        $stored = (clone $query)->pluck('service_id')->all();

        (clone $query)->whereNotIn('service_id', $wanted)->delete();

        foreach (array_diff($wanted, $stored) as $service) {
            VoucherDefinitionServiceModel::query()->create([
                'id' => (string) Str::uuid7(),
                'barbershop_id' => $barbershop,
                'voucher_definition_id' => $definition->id->value,
                'service_id' => $service,
            ]);
        }
    }

    /**
     * The definitions of the current barbershop: no query of this repository leaves it; the row-level policy is the second barrier.
     *
     * @return Builder<VoucherDefinitionModel>
     */
    private function ofBarbershop(): Builder
    {
        return VoucherDefinitionModel::query()->where('barbershop_id', $this->context->current()->value);
    }
}
