<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Infrastructure\Http\Resources;

use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Waitlist\Entries\Application\DTOs\EntryView;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistStatus;

/**
 * @property-read EntryView $resource
 */
#[SchemaName('FilaDeEspera')]
final class WaitlistEntryResource extends JsonResource
{
    /**
     * Expose the row: what the customer waits for, its state and the instant it joined, which is its place in the line.
     *
     * @return array{id: string, servicio: string, barbero: string|null, barbero_nombre: string|null, fecha: string, estado: string, ingreso: string}
     */
    public function toArray(Request $request): array
    {
        $entry = $this->resource->entry;

        return [
            'id' => $entry->id->value,
            /** Identificador del servicio por el que espera. */
            'servicio' => $entry->serviceId->value,
            /** Identificador del barbero que pidió; nulo significa «cualquiera». */
            'barbero' => $entry->collaboratorId?->value,
            /** Nombre del barbero que pidió; nulo si pidió «cualquiera» o si ya no se conoce. */
            'barbero_nombre' => $this->resource->collaboratorName,
            /** Día que busca, `AAAA-MM-DD`. */
            'fecha' => $entry->desiredDate->value,
            /** `esperando` mientras espera su turno u `ofrecida` cuando ya tiene un espacio ofrecido. */
            'estado' => match ($entry->status) {
                WaitlistStatus::Waiting => 'esperando',
                WaitlistStatus::Offered => 'ofrecida',
                WaitlistStatus::Fulfilled => 'cumplida',
                WaitlistStatus::Left => 'retirada',
            },
            /** Cuándo entró a la fila, ISO 8601 con el desfase de Costa Rica (-06:00); ordena la fila: primero en entrar, primero en recibir una oferta. Ejemplo: 2030-03-01T08:00:00-06:00. */
            'ingreso' => $entry->joinedAt->setTimezone(new DateTimeZone((string) config('app.timezone')))->format(DATE_ATOM),
        ];
    }
}
