<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Http\Resources;

use DateTimeImmutable;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Waitlist\Offers\Application\DTOs\OfferView;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferStatus;

/**
 * @property-read OfferView $resource
 */
#[SchemaName('OfertaDeEspera')]
final class OfferResource extends JsonResource
{
    /**
     * Expose the offer: the space it holds and until when, which is what the customer needs to decide.
     *
     * @return array{id: string, fila: string, servicio: string, barbero: string, inicio: string, fin: string, vence_en: string, estado: string, reserva: string|null}
     */
    public function toArray(Request $request): array
    {
        $offer = $this->resource->offer;

        return [
            'id' => $offer->id->value,
            /** Identificador de la fila de espera a la que se le hizo la oferta. */
            'fila' => $offer->entryId->value,
            /** Identificador del servicio por el que espera. */
            'servicio' => $this->resource->serviceId,
            /** Identificador del barbero cuyo espacio se ofrece. */
            'barbero' => $offer->collaboratorId->value,
            /** Inicio del espacio ofrecido, ISO 8601 con el desfase de Costa Rica (-06:00). La reserva que nace al aceptar empieza aquí. Ejemplo: 2030-03-05T10:00:00-06:00. */
            'inicio' => self::instant($offer->interval->start),
            /** Fin del espacio que la oferta aparta, limpieza incluida: puede ser posterior al fin del servicio. Ejemplo: 2030-03-05T10:50:00-06:00. */
            'fin' => self::instant($offer->interval->end),
            /** Hasta cuándo se puede responder: 15 minutos desde que se hizo la oferta (LE-04). Pasada esa hora la oferta está perdida. */
            'vence_en' => self::instant($offer->expiresAt),
            /** `pendiente` mientras espera la respuesta (también si ya se aceptó con pago y falta confirmarlo), `aceptada` cuando la reserva quedó confirmada, `rechazada` o `vencida`. */
            'estado' => match ($offer->status) {
                OfferStatus::Pending => 'pendiente',
                OfferStatus::Accepted => 'aceptada',
                OfferStatus::Rejected => 'rechazada',
                OfferStatus::Expired => 'vencida',
            },
            /** Identificador de la reserva que se creó al aceptar, o nulo si todavía no se aceptó. Si está `temporal`, se paga con `POST /reservas/{reserva}/pagos` antes de `vence_en`. */
            'reserva' => $offer->reservationId?->value,
        ];
    }

    private static function instant(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone((string) config('app.timezone')))->format(DATE_ATOM);
    }
}
