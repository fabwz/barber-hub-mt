<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;
use Src\Reservations\Reservations\Application\DTOs\ExtractedDayPart;
use Src\Reservations\Reservations\Application\DTOs\ProposalCandidate;
use Src\Reservations\Reservations\Application\DTOs\ProposalField;
use Src\Reservations\Reservations\Application\DTOs\ProposalStatus;
use Src\Reservations\Reservations\Application\DTOs\ReservationProposal;
use Src\Reservations\Reservations\Domain\ValueObjects\SlotAlternative;

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
     * Expose the fields of the phrase, the verdict of the validation and what comes from our own catalog and availability. Only
     * these members exist: nothing the provider might add reaches this point.
     *
     * @return array{servicio: string|null, barbero: string|null, fecha: string|null, hora: string|null, franja: array{nombre: string, desde: string, hasta: string}|null, campos_faltantes: list<string>, aclaracion: string|null, validada: bool, estado: string, campo: string|null, servicio_id: string|null, barbero_id: string|null, inicio: string|null, fin: string|null, precio: int|null, candidatos: list<array{id: string, nombre: string}>, espacios: list<array{barbero: string, inicio: string, fin: string}>, alternativas: list<array{barbero: string, inicio: string, fin: string}>}
     */
    public function toArray(Request $request): array
    {
        $proposal = $this->proposal;

        return [
            /** Servicio como lo dijo la persona, sin cambios; `null` si no lo mencionó. El del catálogo está en `servicio_id`. */
            'servicio' => $proposal->service,
            /** Barbero como lo dijo la persona, sin cambios; `null` si no lo mencionó (es opcional). El del catálogo está en `barbero_id`. */
            'barbero' => $proposal->collaborator,
            /** Día `AAAA-MM-DD` en la zona horaria de Costa Rica, con «hoy», «mañana» o «el viernes» ya resueltos; `null` si no lo mencionó o ya pasó. */
            'fecha' => $proposal->date,
            /** Hora `HH:MM` de 24 horas; `null` si la frase no dio una hora exacta. */
            'hora' => $proposal->time,
            /** Franja del día cuando la frase dio una en vez de una hora: `manana` (6:00 a 11:59), `tarde` (12:00 a 17:59) o `noche` (18:00 a 23:59). */
            'franja' => $proposal->dayPart === null ? null : [
                'nombre' => $this->dayPartName($proposal->dayPart),
                'desde' => $proposal->dayPart->startsAt(),
                'hasta' => $proposal->dayPart->endsAt(),
            ],
            /** Lo que falta para poder reservar: `servicio`, `fecha` y `hora` (la franja cuenta como hora). El barbero nunca falta. */
            'campos_faltantes' => array_map($this->fieldName(...), $proposal->missing),
            /** Pregunta o aviso corto para la persona según el `estado`; `null` si la propuesta es `valida`. */
            'aclaracion' => $this->message(),
            /** `true` solo si el `estado` es `valida`: el servicio y el barbero salen de nuestro catálogo y el espacio existe y está libre. Nunca crea una reserva. */
            'validada' => $proposal->status === ProposalStatus::Valid,
            /** Resultado de la validación contra el catálogo y la disponibilidad reales: `valida`, `incompleta`, `ambigua`, `no_reconocida` o `no_disponible`. */
            'estado' => $this->statusName($proposal->status),
            /** Campo que falló o que sigue abierto: `servicio`, `barbero`, `fecha` u `hora`; `null` si el estado es `valida`. */
            'campo' => $proposal->field === null ? null : $this->fieldName($proposal->field),
            /** Identificador del servicio del catálogo de la barbería; `null` mientras el texto no se haya resuelto a uno solo. */
            'servicio_id' => $proposal->serviceId,
            /** Identificador del barbero del catálogo (activo y que ofrece ese servicio); `null` si no se nombró o no se resolvió. */
            'barbero_id' => $proposal->collaboratorId,
            /** Inicio del espacio libre en ISO 8601 con desfase, listo para `POST /reservas`; solo con estado `valida` y hora exacta. */
            'inicio' => $proposal->startsAt?->format(DATE_ATOM),
            /** Fin del servicio (sin la limpieza) del espacio de `inicio`. */
            'fin' => $proposal->endsAt?->format(DATE_ATOM),
            /** Precio en colones enteros, con IVA incluido, tomado del catálogo; `null` mientras no haya un servicio resuelto. */
            'precio' => $proposal->price?->colones,
            /** Opciones del catálogo (`id` y `nombre`) para que la persona elija: servicios o barberos, según `campo`. Vacío si no hay ambigüedad. */
            'candidatos' => array_map(fn (ProposalCandidate $candidate): array => ['id' => $candidate->id, 'nombre' => $candidate->name], $proposal->candidates),
            /** Espacios libres reales que encajan con lo pedido (la franja, o la hora exacta cuando falta elegir barbero), con `barbero`, `inicio` y `fin`. */
            'espacios' => $this->slots($proposal->spaces),
            /** Hasta 3 espacios libres del mismo día y servicio, los de inicio más próximo al pedido, cuando el estado es `no_disponible` (RE-02). */
            'alternativas' => $this->slots($proposal->alternatives),
        ];
    }

    /**
     * A list of free spaces as the booking publishes them.
     *
     * @param  list<SlotAlternative>  $slots
     * @return list<array{barbero: string, inicio: string, fin: string}>
     */
    private function slots(array $slots): array
    {
        return array_map(fn (SlotAlternative $slot): array => [
            'barbero' => $slot->collaboratorId->value,
            'inicio' => $slot->interval->start->format(DATE_ATOM),
            'fin' => $slot->interval->end->format(DATE_ATOM),
        ], $slots);
    }

    /** The short text for the person: the question of the first missing field, or the notice of the verdict. */
    private function message(): ?string
    {
        $proposal = $this->proposal;

        return match ($proposal->status) {
            ProposalStatus::Valid => null,
            ProposalStatus::Incomplete => $proposal->missing === [] ? null : Lang::string("reservations.clarification.{$this->fieldName($proposal->missing[0])}"),
            default => Lang::string("reservations.proposal.{$proposal->status->value}.".$this->fieldName($proposal->field ?? ProposalField::Time)),
        };
    }

    /** The name of a field in the API. */
    private function fieldName(ProposalField $field): string
    {
        return match ($field) {
            ProposalField::Service => 'servicio',
            ProposalField::Collaborator => 'barbero',
            ProposalField::Date => 'fecha',
            ProposalField::Time => 'hora',
        };
    }

    /** The stable name of a status in the API. */
    private function statusName(ProposalStatus $status): string
    {
        return match ($status) {
            ProposalStatus::Valid => 'valida',
            ProposalStatus::Incomplete => 'incompleta',
            ProposalStatus::Ambiguous => 'ambigua',
            ProposalStatus::Unrecognized => 'no_reconocida',
            ProposalStatus::Unavailable => 'no_disponible',
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
