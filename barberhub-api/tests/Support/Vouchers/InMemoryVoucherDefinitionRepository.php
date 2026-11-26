<?php

declare(strict_types=1);

namespace Tests\Support\Vouchers;

use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;
use Src\Vouchers\VoucherDefinitions\Domain\Contracts\VoucherDefinitionRepository;
use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\ServiceId;

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

    /** Page the stored definitions that are on sale, ordered by name. */
    public function pageOnSale(PageRequest $page): Page
    {
        $onSale = array_values(array_filter($this->all(), fn (VoucherDefinition $definition): bool => $definition->isActive));

        return new Page(array_slice($onSale, $page->offset(), $page->size), count($onSale), $page);
    }

    /**
     * The stored definitions among the identifiers, on sale or not, ordered by name.
     *
     * @param  list<VoucherDefinitionId>  $ids
     * @return list<VoucherDefinition>
     */
    public function findMany(array $ids): array
    {
        $keys = array_map(fn (VoucherDefinitionId $id): string => $id->value, $ids);

        return array_values(array_filter($this->all(), fn (VoucherDefinition $definition): bool => in_array($definition->id->value, $keys, true)));
    }

    /**
     * The definitions that cover the service.
     *
     * @return list<string>
     */
    public function idsCoveringService(ServiceId $service): array
    {
        return array_values(array_map(
            fn (VoucherDefinition $definition): string => $definition->id->value,
            array_filter($this->definitions, fn (VoucherDefinition $definition): bool => $definition->services->covers($service)),
        ));
    }

    /** Tell whether the test marked the definition as sold. */
    public function hasPurchases(VoucherDefinitionId $id): bool
    {
        return isset($this->sold[$id->value]);
    }

    /** The given definitions that the test marked as sold. */
    public function idsWithPurchases(array $ids): array
    {
        return array_values(array_filter(
            array_map(static fn (VoucherDefinitionId $id): string => $id->value, $ids),
            fn (string $id): bool => isset($this->sold[$id]),
        ));
    }

    /** Make a customer have bought the definition. */
    public function markSold(VoucherDefinitionId $id): void
    {
        $this->sold[$id->value] = true;
    }
}
