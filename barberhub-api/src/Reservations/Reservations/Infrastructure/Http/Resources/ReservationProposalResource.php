<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;
use Src\Reservations\Reservations\Application\DTOs\ExtractedDayPart;
use Src\Reservations\Reservations\Application\DTOs\ProposalField;
use Src\Reservations\Reservations\Application\DTOs\ReservationProposal;

/**
 * @property-read ReservationProposal $resource
 */
#[SchemaName('PropuestaDeReserva')]
final class ReservationProposalResource extends JsonResource
{
    /** Wrap a proposal. */
    public function __construct(private readonly ReservationProposal $proposal)
    {
        parent::__construct($proposal);
    }

    /**
     * Expose the four fields, the part of the day, what is missing and the question to ask. Only these members exist: nothing the
     * provider might add reaches this point.
     *
     * @return array{servicio: string|null, barbero: string|null, fecha: string|null, hora: string|null, franja: array{nombre: string, desde: string, hasta: string}|null, campos_faltantes: list<string>, aclaracion: string|null, validada: false}
     */
    public function toArray(Request $request): array
    {
        $missing = $this->proposal->missing;

        return [
            /** Servicio como lo dijo la persona, sin comprobar contra el catálogo; `null` si no lo mencionó. */
            'servicio' => $this->proposal->service,
            /** Barbero como lo dijo la persona, sin comprobar; `null` si no lo mencionó (es opcional). */
            'barbero' => $this->proposal->collaborator,
            /** Día `AAAA-MM-DD` en la zona horaria de Costa Rica, con «hoy», «mañana» o «el viernes» ya resueltos; `null` si no lo mencionó o ya pasó. */
            'fecha' => $this->proposal->date,
            /** Hora `HH:MM` de 24 horas; `null` si la frase no dio una hora exacta. */
            'hora' => $this->proposal->time,
            /** Franja del día cuando la frase dio una en vez de una hora: `manana` (6:00 a 11:59), `tarde` (12:00 a 17:59) o `noche` (18:00 a 23:59). */
            'franja' => $this->proposal->dayPart === null ? null : [
                'nombre' => $this->dayPartName($this->proposal->dayPart),
                'desde' => $this->proposal->dayPart->startsAt(),
                'hasta' => $this->proposal->dayPart->endsAt(),
            ],
            /** Lo que falta para poder reservar: `servicio`, `fecha` y `hora` (la franja cuenta como hora). El barbero nunca falta. */
            'campos_faltantes' => array_map($this->fieldName(...), $missing),
            /** Pregunta corta para pedir lo primero que falta; `null` si no falta nada. */
            'aclaracion' => $missing === [] ? null : Lang::string("reservations.clarification.{$this->fieldName($missing[0])}"),
            /** Siempre `false`: la propuesta no se contrastó con el catálogo ni con la disponibilidad real, y no creó ninguna reserva. */
            'validada' => false,
        ];
    }

    /** The name of a missing field in the API. */
    private function fieldName(ProposalField $field): string
    {
        return match ($field) {
            ProposalField::Service => 'servicio',
            ProposalField::Date => 'fecha',
            ProposalField::Time => 'hora',
        };
    }

    /** The name of a part of the day in the API. */
    private function dayPartName(ExtractedDayPart $dayPart): string
    {
        return match ($dayPart) {
            ExtractedDayPart::Morning => 'manana',
            ExtractedDayPart::Afternoon => 'tarde',
            ExtractedDayPart::Evening => 'noche',
        };
    }
}
