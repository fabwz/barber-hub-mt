<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Src\Catalog\RentInstallments\Application\Contracts\BarbershopBlockState;
use Src\Catalog\RentInstallments\Application\Contracts\BarbershopScope;
use Src\Catalog\RentInstallments\Application\Contracts\RegisteredBarbershops;
use Src\Catalog\RentInstallments\Application\UseCases\BlockBarbershopForOverdueRent;
use Src\Catalog\RentInstallments\Application\UseCases\CountPendingRentMonths;
use Src\Catalog\RentInstallments\Application\UseCases\UnblockBarbershopWithoutPendingRent;
use Src\Catalog\RentInstallments\Domain\Contracts\RentInstallmentRepository;
use Src\Catalog\RentInstallments\Domain\Entities\RentInstallment;
use Src\Catalog\RentInstallments\Domain\Services\RentCalendar;
use Src\Catalog\RentInstallments\Domain\ValueObjects\InstallmentStatus;
use Src\Catalog\RentInstallments\Domain\ValueObjects\RentInstallmentId;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Money;
use Tests\Support\Shared\InMemoryAuditLog;

/**
 * The ports of the rent blocking in memory, for the Unit tests: the installments, the lock state of the barbershops, their list, the
 * scope of a barbershop and the technical record, already wired to the use cases.
 */
final class InMemoryRentBlocking implements BarbershopBlockState, BarbershopScope, RegisteredBarbershops, RentInstallmentRepository
{
    public readonly InMemoryAuditLog $audit;

    /** @var array<string, DateTimeImmutable> */
    private array $blockedAt = [];

    /** @var list<RentInstallment> */
    private array $installments = [];

    /** @var list<string> */
    private array $barbershops = [];

    /** @var list<string> */
    private array $failing = [];

    private int $sequence = 0;

    public function __construct()
    {
        $this->audit = new InMemoryAuditLog;
    }

    /** The use case that blocks one barbershop, over these ports. */
    public function blocker(): BlockBarbershopForOverdueRent
    {
        return new BlockBarbershopForOverdueRent($this, $this, new CountPendingRentMonths($this), $this->audit);
    }

    /** The use case that unblocks one barbershop, over these ports. */
    public function unblocker(): UnblockBarbershopWithoutPendingRent
    {
        return new UnblockBarbershopWithoutPendingRent($this, new CountPendingRentMonths($this), $this->audit);
    }

    /**
     * Register a barbershop with an installment for each due date, all in the same status.
     *
     * @param  list<string>  $dueDates  Days as `Y-m-d`.
     */
    public function withInstallments(string $barbershopId, array $dueDates, InstallmentStatus $status = InstallmentStatus::Pending): self
    {
        $this->barbershops[] = $barbershopId;

        foreach ($dueDates as $dueOn) {
            $this->installments[] = RentInstallment::reconstitute(new RentInstallmentId(sprintf('0192f0c4-7b1e-7c3a-9f1d-%012d', ++$this->sequence)), new BarbershopId($barbershopId), Money::of(25000), $this->day($dueOn), $status);
        }

        return $this;
    }

    /** Make the processing of this barbershop fail. */
    public function failingOn(string $barbershopId): self
    {
        $this->failing[] = $barbershopId;

        return $this;
    }

    /** Pay every installment of the barbershop. */
    public function payAll(string $barbershopId): void
    {
        $this->installments = array_map(
            static fn (RentInstallment $installment): RentInstallment => $installment->barbershopId->value === $barbershopId
                ? RentInstallment::reconstitute($installment->id, $installment->barbershopId, $installment->amount, $installment->dueOn, InstallmentStatus::Paid)
                : $installment,
            $this->installments,
        );
    }

    /** Whether the barbershop is blocked. */
    public function isBlocked(string $barbershopId): bool
    {
        return isset($this->blockedAt[$barbershopId]);
    }

    public function run(string $barbershopId, Closure $operation): mixed
    {
        return in_array($barbershopId, $this->failing, true) ? throw new RuntimeException('The barbershop cannot be processed.') : $operation();
    }

    public function ids(): array
    {
        return $this->barbershops;
    }

    public function registeredAt(string $barbershopId): ?DateTimeImmutable
    {
        return null;
    }

    public function lock(string $barbershopId): ?DateTimeImmutable
    {
        return $this->blockedAt[$barbershopId] ?? null;
    }

    public function block(string $barbershopId, DateTimeImmutable $at): bool
    {
        if (isset($this->blockedAt[$barbershopId])) {
            return false;
        }

        $this->blockedAt[$barbershopId] = $at;

        return true;
    }

    public function unblock(string $barbershopId): bool
    {
        $was = isset($this->blockedAt[$barbershopId]);
        unset($this->blockedAt[$barbershopId]);

        return $was;
    }

    public function nextId(): RentInstallmentId
    {
        return new RentInstallmentId(sprintf('0192f0c4-7b1e-7c3a-9f1d-%012d', ++$this->sequence));
    }

    public function addIfMissing(RentInstallment $installment): bool
    {
        $this->installments[] = $installment;

        return true;
    }

    public function latestDueOn(BarbershopId $barbershopId): ?DateTimeImmutable
    {
        return null;
    }

    public function unpaidOf(BarbershopId $barbershopId): array
    {
        return array_values(array_filter(
            $this->installments,
            static fn (RentInstallment $installment): bool => $installment->barbershopId->value === $barbershopId->value && $installment->status === InstallmentStatus::Pending,
        ));
    }

    private function day(string $dueOn): DateTimeImmutable
    {
        return new DateTimeImmutable($dueOn, new DateTimeZone(RentCalendar::ZONE));
    }
}
