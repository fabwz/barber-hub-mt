<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application\UseCases;

use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;
use Src\Vouchers\VoucherDefinitions\Application\DescribeCoveredServices;
use Src\Vouchers\VoucherDefinitions\Application\DTOs\CoveredService;
use Src\Vouchers\VoucherDefinitions\Domain\Contracts\VoucherDefinitionRepository;

final readonly class ListCoveredServices
{
    /** Receive the repository and the reader of the names of the covered services. */
    public function __construct(private VoucherDefinitionRepository $definitions, private DescribeCoveredServices $describe) {}

    /**
     * The services that each of the definitions covers, read from the definition as it is now, on sale or withdrawn (BO-06): a voucher
     * already bought keeps no copy of them. A definition of another barbershop is not found, so it is left out.
     *
     * @param  list<string>  $definitionIds
     * @return array<string, list<CoveredService>> Indexed by the identifier of the definition.
     */
    public function execute(array $definitionIds): array
    {
        return $this->describe->execute($this->definitions->findMany(array_map(fn (string $id): VoucherDefinitionId => new VoucherDefinitionId($id), $definitionIds)));
    }
}
