<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Vouchers\VoucherDefinitions\Application\Contracts\CatalogServices;
use Src\Vouchers\VoucherDefinitions\Domain\Contracts\VoucherDefinitionRepository;
use Src\Vouchers\VoucherDefinitions\Infrastructure\Persistence\Repositories\EloquentVoucherDefinitionRepository;
use Src\Vouchers\VoucherDefinitions\Infrastructure\Services\CatalogServicesAdapter;

final class VoucherDefinitionsServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        VoucherDefinitionRepository::class => EloquentVoucherDefinitionRepository::class,
        CatalogServices::class => CatalogServicesAdapter::class,
    ];
}
