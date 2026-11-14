<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Vouchers\VoucherDefinitions\Domain\Contracts\VoucherDefinitionRepository;
use Src\Vouchers\VoucherDefinitions\Infrastructure\Persistence\Repositories\EloquentVoucherDefinitionRepository;

final class VoucherDefinitionsServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters. The module has no routes yet: they arrive with the definition of vouchers (#87).
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        VoucherDefinitionRepository::class => EloquentVoucherDefinitionRepository::class,
    ];
}
