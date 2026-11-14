<?php

declare(strict_types=1);

namespace Tests\Support\Vouchers;

use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;
use Src\Vouchers\VoucherDefinitions\Domain\Contracts\VoucherDefinitionRepository;
use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;

final class InMemoryVoucherDefinitionRepository implements VoucherDefinitionRepository
{
    /** @var array<string, VoucherDefinition> */
    private array $definitions = [];

    /** @var array<string, true> */
    private array $sold = [];

    private int $sequence = 0;

    /** Generate a predictable identity. */
    public function nextId(): VoucherDefinitionId
    {
        return new VoucherDefinitionId(sprintf('0192f0c4-0000-7000-8000-%012d', ++$this->sequence));
    }

    /** Store the definition. */
    public function save(VoucherDefinition $definition): void
    {
        $this->definitions[$definition->id->value] = $definition;
    }

    /** Find a definition by its identity. */
    public function find(VoucherDefinitionId $id): ?VoucherDefinition
    {
        return $this->definitions[$id->value] ?? null;
    }

    /** Find a definition by its identity; there is no concurrency to lock against in memory. */
    public function findForUpdate(VoucherDefinitionId $id): ?VoucherDefinition
    {
        return $this->find($id);
    }

    /**
     * List the stored definitions ordered by name.
     *
     * @return list<VoucherDefinition>
     */
    public function all(): array
    {
        $definitions = array_values($this->definitions);
        usort($definitions, fn (VoucherDefinition $a, VoucherDefinition $b): int => $a->name->value <=> $b->name->value);

        return $definitions;
    }

    /** Tell whether the test marked the definition as sold. */
    public function hasPurchases(VoucherDefinitionId $id): bool
    {
        return isset($this->sold[$id->value]);
    }

    /** Make a customer have bought the definition. */
    public function markSold(VoucherDefinitionId $id): void
    {
        $this->sold[$id->value] = true;
    }
}
