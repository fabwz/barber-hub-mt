<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Catalog\Collaborators\Application\DTOs\CollaboratorWithAccount;
use Src\Catalog\Collaborators\Domain\ValueObjects\BreakPeriod;
use Src\Catalog\Collaborators\Domain\ValueObjects\OfferedServiceId;
use Src\Catalog\Collaborators\Domain\ValueObjects\Shift;

/**
 * @property-read CollaboratorWithAccount $resource
 */
#[SchemaName('Colaborador')]
final class CollaboratorResource extends JsonResource
{
    /** Wrap a collaborator with the name and email of its account. */
    public function __construct(private readonly CollaboratorWithAccount $item)
    {
        parent::__construct($item);
    }

    /**
     * Expose the collaborator with the vocabulary of the business. The temporary password is never part of it.
     *
     * @return array{id: string, nombre: string, correo: string, activo: bool, servicios: list<string>, turnos: list<array{dia: int, inicia: string, termina: string}>, pausas: list<array{dia: int, inicia: string, termina: string}>}
     */
    public function toArray(Request $request): array
    {
        $collaborator = $this->item->collaborator;

        return [
            'id' => $collaborator->id->value,
            'nombre' => $this->item->name,
            'correo' => $this->item->email,
            'activo' => $collaborator->active,
            'servicios' => array_map(fn (OfferedServiceId $service): string => $service->value, $collaborator->services),
            'turnos' => array_map(fn (Shift $shift): array => [
                'dia' => $shift->day->value,
                'inicia' => $shift->range->start->value,
                'termina' => $shift->range->end->value,
            ], $collaborator->schedule->shifts),
            'pausas' => array_map(fn (BreakPeriod $break): array => [
                'dia' => $break->day->value,
                'inicia' => $break->range->start->value,
                'termina' => $break->range->end->value,
            ], $collaborator->schedule->breaks),
        ];
    }
}
