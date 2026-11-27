<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Infrastructure\Http\Resources;

use DateTimeImmutable;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Catalog\Absences\Domain\Entities\Absence;

/**
 * @property-read Absence $resource
 */
#[SchemaName('Ausencia')]
final class AbsenceResource extends JsonResource
{
    /** Wrap an absence entity. */
    public function __construct(private readonly Absence $absence)
    {
        parent::__construct($absence);
    }

    /**
     * Expose the absence with the vocabulary of the business.
     *
     * @return array{id: string, colaborador: string, inicio: string, fin: string, motivo: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->absence->id->value,
            'colaborador' => $this->absence->collaboratorId->value,
            /** Inicio del bloqueo, fecha y hora ISO 8601 con el desfase de Costa Rica (-06:00). */
            'inicio' => $this->moment($this->absence->interval->start),
            /** Fin del bloqueo, que no se incluye: un bloqueo de días completos termina a la medianoche del día siguiente al último. */
            'fin' => $this->moment($this->absence->interval->end),
            'motivo' => $this->absence->reason?->value,
        ];
    }

    /** Write an instant as ISO 8601 in the time zone of the application, Costa Rica. */
    private function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone((string) config('app.timezone')))->format(DATE_ATOM);
    }
}
