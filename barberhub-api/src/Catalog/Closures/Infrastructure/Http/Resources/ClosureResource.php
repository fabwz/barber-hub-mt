<?php

declare(strict_types=1);

namespace Src\Catalog\Closures\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Catalog\Closures\Domain\Entities\Closure;

/**
 * @property-read Closure $resource
 */
#[SchemaName('Cierre')]
final class ClosureResource extends JsonResource
{
    /** Wrap a closure entity. */
    public function __construct(private readonly Closure $closure)
    {
        parent::__construct($closure);
    }

    /**
     * Expose the closure with the vocabulary of the business.
     *
     * @return array{id: string, fecha: string, motivo: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->closure->id->value,
            /** @format date */
            'fecha' => $this->closure->date->value,
            'motivo' => $this->closure->reason?->value,
        ];
    }
}
