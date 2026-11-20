<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Src\Shared\Domain\Contracts\Clock;
use Src\Waitlist\Offers\Application\UseCases\AcceptOffer;
use Src\Waitlist\Offers\Application\UseCases\ListMyOffers;
use Src\Waitlist\Offers\Application\UseCases\RejectOffer;
use Src\Waitlist\Offers\Infrastructure\Http\Requests\AcceptOfferRequest;
use Src\Waitlist\Offers\Infrastructure\Http\Resources\OfferResource;

#[Group('Ofertas de la lista de espera', 'Ver la oferta de un espacio liberado, aceptarla con pago o con bono, o rechazarla.')]
final readonly class OfferController
{
    /** Receive the port that tells the current instant, which decides whether an offer is still open. */
    public function __construct(private Clock $clock) {}

    /** List the open offers of the person. */
    #[Endpoint(
        title: 'Listar mis ofertas',
        description: 'Lista, de la primera que se hizo a la última, las ofertas que la persona de la sesión todavía puede responder en la barbería: las `pendiente` dentro de sus 15 minutos, de sus propias filas `ofrecida` (a lo sumo 3, así que no va paginada). Cada una trae el espacio que aparta: `barbero`, `inicio` y `fin` (ISO 8601 con el desfase de Costa Rica) y `vence_en`, la hora límite para responder. Una oferta vencida, aceptada o rechazada no aparece. Una persona que todavía no se anotó ahí recibe la lista vacía, no un error. No muestra ofertas de otras personas. El dueño o un colaborador de esa barbería recibe 403 `prohibido`.',
    )]
    public function mine(Request $request, ListMyOffers $listOffers): AnonymousResourceCollection
    {
        return OfferResource::collection($listOffers->execute((string) $request->user()?->getAuthIdentifier(), $this->clock->now()));
    }

    /** Accept an offer. */
    #[Endpoint(
        title: 'Aceptar una oferta',
        description: 'Acepta la oferta y reserva el espacio que aparta, para el `servicio` de la fila y con el mismo flujo de una reserva (`POST /reservas`): se vuelve a validar todo bajo el bloqueo del barbero y el precio, la duración y el plazo de cancelación son los de ahora. La respuesta trae la oferta con el identificador de la `reserva`. Con `con_bono` en `true` la reserva queda `confirmada` de inmediato, sin depósito, y se aparta una sesión de un bono de la persona (RF-28): la oferta pasa a `aceptada` y la fila a `cumplida`, que deja de contar para el máximo de 3; sin bono vigente que cubra el servicio responde 409 `saldo-insuficiente`, `bono-vencido` o `conflicto` como `POST /reservas`, y no queda nada: la oferta sigue `pendiente`. Sin `con_bono`, si la barbería cobra la reserva queda `temporal` y se paga con `POST /reservas/{reserva}/pagos` hasta `vence_en`, la hora en que vencería la oferta (no los 10 minutos de una reserva común): solo el pago confirmado por la pasarela, nunca lo que reporte el navegador, la confirma, y entonces la oferta pasa a `aceptada` y la fila a `cumplida`; la oferta sigue `pendiente` hasta ese momento. Si el pago no se confirma antes de `vence_en`, la oferta se pierde: la fila vuelve a esperar con su lugar y el espacio pasa a la siguiente persona compatible (en un máximo de 1 minuto). Si la barbería no cobra, la reserva queda `confirmada` de inmediato. Aceptar es idempotente: repetirlo con la oferta `aceptada`, o `pendiente` con su reserva todavía por pagar, responde 200 con la misma oferta y la misma `reserva`, sin reservar otra vez. Una oferta pasada de sus 15 minutos responde 409 `oferta-vencida`, aunque su tarea no la haya marcado todavía. Una oferta `rechazada`, o cuya fila la persona dejó mientras estaba pendiente, responde 409 `conflicto`. Si el espacio ya no está libre responde 409 `espacio-no-disponible`; si la persona tiene otra reserva confirmada que se solape, 409 `conflicto`. Una oferta de otra persona o de otra barbería, una inexistente y una persona sin rol en la barbería responden el mismo 404 `no-encontrado`. El dueño o un colaborador de esa barbería recibe 403 `prohibido`.',
    )]
    public function accept(AcceptOfferRequest $request, string $barberia, string $oferta, AcceptOffer $acceptOffer): OfferResource
    {
        return new OfferResource($acceptOffer->execute((string) $request->user()?->getAuthIdentifier(), $oferta, $request->withVoucher(), $this->clock->now()));
    }

    /** Turn an offer down. */
    #[Endpoint(
        title: 'Rechazar una oferta',
        description: 'Rechaza la oferta: queda `rechazada`, la fila vuelve a `esperando` con su lugar (no se pierde su turno para otros espacios) y en la misma operación el mismo espacio se ofrece a la siguiente persona compatible, sin esperar los 15 minutos. Quien la rechazó no recibe de nuevo ese espacio. Si no queda nadie, o faltan menos de 60 minutos, el espacio vuelve a ser público. Rechazar es idempotente: repetirlo con la oferta ya `rechazada` responde 200 igual y no ofrece nada otra vez. Una oferta pasada de sus 15 minutos responde 409 `oferta-vencida`; una ya `aceptada` responde 409 `conflicto`, y también una oferta cuya reserva temporal espera su pago (primero se cancela esa reserva). Una oferta de otra persona o de otra barbería, una inexistente y una persona sin rol en la barbería responden el mismo 404 `no-encontrado`. El dueño o un colaborador de esa barbería recibe 403 `prohibido`.',
    )]
    public function reject(Request $request, string $barberia, string $oferta, RejectOffer $rejectOffer): OfferResource
    {
        return new OfferResource($rejectOffer->execute((string) $request->user()?->getAuthIdentifier(), $oferta, $this->clock->now()));
    }
}
