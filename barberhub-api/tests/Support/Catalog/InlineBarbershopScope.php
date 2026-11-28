<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use Closure;
use Src\Catalog\Barbershops\Application\Contracts\BarbershopScope;

final class InlineBarbershopScope implements BarbershopScope
{
    /** @var list<string> */
    private array $scopes = [];

    /** Run the operation straight away and remember which barbershop it ran as. */
    public function run(string $barbershopId, Closure $operation): mixed
    {
        $this->scopes[] = $barbershopId;

        return $operation();
    }

    /**
     * The barbershops the operations ran as, in order.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return $this->scopes;
    }
}
