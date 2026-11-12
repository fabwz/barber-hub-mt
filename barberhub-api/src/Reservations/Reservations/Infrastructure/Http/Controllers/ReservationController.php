<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Src\Reservations\Reservations\Application\QuotePayment;
use Src\Reservations\Reservations\Application\UseCases\CancelReservation;
use Src\Reservations\Reservations\Application\UseCases\CreateReservation;
use Src\Reservations\Reservations\Application\UseCases\GetCustomerReservation;
use Src\Reservations\Reservations\Application\UseCases\GetStaffReservation;
use Src\Reservations\Reservations\Application\UseCases\ListCustomerReservations;
use Src\Reservations\Reservations\Application\UseCases\ListStaffReservations;
use Src\Reservations\Reservations\Application\UseCases\ProposeReservation;
use Src\Reservations\Reservations\Application\UseCases\RescheduleReservation;
use Src\Reservations\Reservations\Infrastructure\Http\Requests\CreateReservationRequest;
use Src\Reservations\Reservations\Infrastructure\Http\Requests\ListMyReservationsRequest;
use Src\Reservations\Reservations\Infrastructure\Http\Requests\ListReservationsRequest;
use Src\Reservations\Reservations\Infrastructure\Http\Requests\ProposeReservationRequest;
use Src\Reservations\Reservations\Infrastructure\Http\Requests\RescheduleReservationRequest;
use Src\Reservations\Reservations\Infrastructure\Http\Resources\CancelledReservationResource;
use Src\Reservations\Reservations\Infrastructure\Http\Resources\CreatedReservationResource;
use Src\Reservations\Reservations\Infrastructure\Http\Resources\CustomerReservationDetailResource;
use Src\Reservations\Reservations\Infrastructure\Http\Resources\CustomerReservationListItemResource;
use Src\Reservations\Reservations\Infrastructure\Http\Resources\ReservationProposalResource;
use Src\Reservations\Reservations\Infrastructure\Http\Resources\ReservationResource;
use Src\Reservations\Reservations\Infrastructure\Http\Resources\StaffReservationDetailResource;
use Src\Reservations\Reservations\Infrastructure\Http\Resources\StaffReservationListItemResource;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Infrastructure\Http\Resources\PageMeta;
use Src\Shared\Infrastructure\Tenancy\RequestBarbershopContext;

#[Group('Reservas', 'Reservar el espacio de un barbero y consultar las reservas según el rol.')]
final readonly class ReservationController
{
    /** Receive the port that tells the current instant. */
    public function __construct(private Clock $clock) {}

    /** Book a space of a barber. */
    #[Endpoint(
        title: 'Crear una reserva',
        description: 'Reserva el espacio de un `barbero` que empieza en `inicio` para un `servicio`, a nombre de la persona de la sesión. Vuelve a validar la disponibilidad en ese instante, con bloqueo del barbero, así que dos peticiones por el mismo espacio dejan exactamente una reserva. Si la barbería no cobra (modalidad `sin_pago`), la reserva queda `confirmada` de inmediato; si cobra, queda `temporal` con la hora límite de pago en `expira_en` (10 minutos). La respuesta informa además `monto_a_pagar` (lo que el servidor cobraría ahora según la política de la barbería), `saldo_en_local` y `vence_en`. La reserva temporal retiene el espacio hasta entonces y expira sola si el pago no llega (el espacio vuelve a estar disponible). Guarda el precio, la duración y el plazo de cancelación (24 horas, provisional) vigentes. Una persona sin rol en la barbería queda como cliente con su primera reserva; si la reserva falla, no queda ninguna membresía. El dueño o un colaborador de esa barbería recibe 403 `prohibido`. Si el espacio ya no está disponible responde 409 `espacio-no-disponible` y el miembro `alternativas` trae hasta 3 espacios del mismo día, barbero y servicio, los de inicio más próximo al pedido, con `barbero`, `inicio` y `fin`. Si la persona ya tiene una reserva confirmada que se solapa en la barbería, responde 409 `conflicto`. Un servicio o un barbero inexistente, inactivo o de otra barbería responde 404 `no-encontrado`, igual que una barbería inexistente; un dato mal escrito, 422 `datos-invalidos` con el campo.',
    )]
    public function store(CreateReservationRequest $request, CreateReservation $createReservation, QuotePayment $quote): JsonResponse
    {
        $now = $this->clock->now();
        $reservation = $createReservation->execute($request->toCommand(), $now);

        return new CreatedReservationResource($reservation, $quote->one($reservation, $now))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** Understand a phrase and propose a reservation without creating it. */
    #[Endpoint(
        title: 'Proponer una reserva a partir de una frase',
        description: 'Convierte una `frase` libre (hasta 300 caracteres), por ejemplo «quiero un corte mañana a las 3 con Kevin», en una propuesta estructurada: `servicio`, `barbero`, `fecha` (`AAAA-MM-DD`, con «hoy», «mañana» o «el viernes» resueltos con la fecha de hoy en Costa Rica; una fecha pasada se descarta), `hora` (`HH:MM`) o `franja` (mañana 6:00 a 11:59, tarde 12:00 a 17:59, noche 18:00 a 23:59), `campos_faltantes` (`servicio`, `fecha` u `hora`; el barbero es opcional y nunca falta) y una `aclaracion` corta para pedir lo primero que falta. La propuesta NO está validada (`validada` es siempre `false`): el servicio y el barbero son el texto que dijo la persona, no se contrastan con el catálogo ni con la disponibilidad real, y no se crea ninguna reserva; para reservar se sigue con `POST /reservas`. La frase nunca se guarda y al proveedor solo viajan la frase, la fecha de hoy y los nombres de los servicios y barberos activos de esa barbería. Ejemplo, con hoy miércoles 7 de octubre de 2026: `{"frase": "corte y barba el sábado en la tarde con Kevin"}` responde `{"data": {"servicio": "corte y barba", "barbero": "Kevin", "fecha": "2026-10-10", "hora": null, "franja": {"nombre": "tarde", "desde": "12:00", "hasta": "17:59"}, "campos_faltantes": [], "aclaracion": null, "validada": false}}`. Con `{"frase": "quiero un corte mañana"}` responde `servicio: "corte"`, `fecha: "2026-10-08"`, `hora: null`, `campos_faltantes: ["hora"]` y `aclaracion: "¿A qué hora le gustaría?"`. Está limitada a 10 peticiones por minuto por persona: la siguiente responde 429 `demasiadas-solicitudes` con `Retry-After`. Si el proveedor de IA no responde a tiempo, está caído, no está configurado o devuelve algo inválido, responde 503 `servicio-no-disponible` y se debe reservar con el formulario normal. Una frase vacía o de más de 300 caracteres responde 422 `datos-invalidos`. El dueño o un colaborador de esa barbería recibe 403 `prohibido`; una barbería inexistente, 404 `no-encontrado`.',
    )]
    public function propose(ProposeReservationRequest $request, ProposeReservation $proposeReservation): ReservationProposalResource
    {
        return new ReservationProposalResource($proposeReservation->execute($request->phrase(), $this->clock->now()));
    }

    /** List the reservations the person made in the barbershop. */
    #[Endpoint(
        title: 'Listar mis reservas',
        description: 'Lista, de la de inicio más próximo a la más lejana, las reservas que la persona de la sesión hizo en la barbería, con el nombre del `servicio` y del `barbero`. Se puede filtrar por `dia` (fecha de Costa Rica) y por `estado`. Una persona que todavía no reservó ahí recibe la lista vacía, no un error. El dueño o un colaborador de esa barbería recibe 403 `prohibido`: su vista es `GET /reservas`. Va paginada con `pagina` y `por_pagina` (hasta 50, 15 por defecto) y `meta` trae el total.',
    )]
    public function mine(ListMyReservationsRequest $request, ListCustomerReservations $listReservations): AnonymousResourceCollection
    {
        $page = $listReservations->execute((string) $request->user()?->getAuthIdentifier(), $request->search(), $request->page(), $this->clock->now());

        return CustomerReservationListItemResource::collection($page->items)->additional(PageMeta::of($page));
    }

    /** List the reservations of the agenda the owner or the collaborator may see. */
    #[Endpoint(
        title: 'Listar las reservas de la barbería',
        description: 'Lista, de la de inicio más próximo a la más lejana, las reservas que el rol permite ver, con el nombre del `servicio`, del `barbero` y del cliente (`cliente_nombre`; nunca su correo ni su teléfono). El dueño ve las de todos los colaboradores de su barbería y puede filtrar por `colaborador`; un colaborador ve solo las de su propio perfil y, si pide el `colaborador` de otro, recibe el mismo 404 `no-encontrado` de un recurso inexistente. Un colaborador que no existe en la barbería responde 404 también al dueño. Se puede filtrar además por `dia` (fecha de Costa Rica) y por `estado`. Una persona sin rol en la barbería recibe 404 y un cliente, 403 `prohibido`. Va paginada con `pagina` y `por_pagina` (hasta 50, 15 por defecto) y `meta` trae el total.',
    )]
    public function index(ListReservationsRequest $request, ListStaffReservations $listReservations): AnonymousResourceCollection
    {
        $isOwner = $request->attributes->get(RequestBarbershopContext::ROLE_ATTRIBUTE) === 'owner';
        $page = $listReservations->execute((string) $request->user()?->getAuthIdentifier(), $isOwner, $request->search(), $request->page(), $this->clock->now());

        return StaffReservationListItemResource::collection($page->items)->additional(PageMeta::of($page));
    }

    /** Show one reservation with what the role of the person may see. */
    #[Endpoint(
        title: 'Mostrar una reserva',
        description: 'Muestra una reserva con la proyección de cada rol. El cliente ve la suya (`ReservaDetalleDeCliente`): servicio, barbero, fecha, estado, precio y plazo de cancelación, sin saldo ni historial. El colaborador ve las de su propia agenda (`ReservaDetalleDeAgenda`) con el nombre del cliente y el `saldo_pendiente`, sin historial, pagos ni ingresos. El dueño ve cualquiera de su barbería con lo mismo y el `historial` de cambios de estado con `de`, `a`, `autor` (nombre, o `sistema`) y `fecha`. Sin pagos todavía, el `saldo_pendiente` es el precio completo, que se cobra en el local. Una reserva que no existe, de otra barbería, de otro cliente o de otro colaborador, y una persona sin rol en la barbería, responden el mismo 404 `no-encontrado`.',
    )]
    public function show(Request $request, string $barberia, string $reserva, GetStaffReservation $getStaff, GetCustomerReservation $getCustomer): CustomerReservationDetailResource|StaffReservationDetailResource
    {
        $userId = (string) $request->user()?->getAuthIdentifier();
        $role = $request->attributes->get(RequestBarbershopContext::ROLE_ATTRIBUTE);

        return $role === 'customer'
            ? new CustomerReservationDetailResource($getCustomer->execute($userId, $reserva, $this->clock->now()))
            : new StaffReservationDetailResource($getStaff->execute($userId, $role === 'owner', $reserva, $this->clock->now()));
    }

    /** Cancel a reservation and free its space. */
    #[Endpoint(
        title: 'Cancelar una reserva',
        description: 'Cancela la reserva y libera su espacio en la misma operación. El cliente cancela solo las suyas y la reserva queda `cancelada_por_cliente`; el dueño cancela cualquiera de su barbería y queda `cancelada_por_barberia`. La respuesta trae la `clasificacion` de la cancelación: `a_tiempo` o `tardia` (según el `plazo_cancelacion_horas` que la reserva guardó y la hora de la cancelación) para el cliente, y `por_la_barberia` para el dueño. Repetir la cancelación responde 200 con el mismo estado y la misma clasificación, sin agregar nada al historial. Una reserva completada, ausente o expirada, o una temporal que el dueño intenta cancelar, responde 409 `conflicto`. Un colaborador recibe 403 `prohibido`. Una reserva que no existe, de otra barbería o de otro cliente, y una persona sin rol en la barbería, responden el mismo 404 `no-encontrado`. La consecuencia económica, el aviso al cliente y la lista de espera todavía no se aplican.',
    )]
    public function cancel(Request $request, string $barberia, string $reserva, CancelReservation $cancelReservation): CancelledReservationResource
    {
        $isOwner = $request->attributes->get(RequestBarbershopContext::ROLE_ATTRIBUTE) === 'owner';

        return new CancelledReservationResource($cancelReservation->execute((string) $request->user()?->getAuthIdentifier(), $isOwner, $reserva, $this->clock->now()));
    }

    /** Move a confirmed reservation to another space. */
    #[Endpoint(
        title: 'Reprogramar una reserva',
        description: 'Mueve una reserva `confirmada` al espacio que empieza en `inicio` y, si se manda `barbero`, con ese barbero; sin él conserva el suyo. Es atómica: bloquea al barbero de destino, vuelve a validar la disponibilidad del nuevo espacio sin contar la propia reserva (moverla 15 minutos no choca consigo misma) y la mueve junto con su fin y su limpieza. Si el espacio ya no está disponible responde 409 `espacio-no-disponible` con hasta 3 `alternativas` del mismo día, barbero y servicio, y la reserva queda como estaba. El precio, la duración y el plazo de cancelación guardados no cambian y no se cobra otro depósito. El cliente reprograma solo las suyas y solo hasta el `plazo_cancelacion_horas` antes del inicio ORIGINAL: después responde 409 `plazo-vencido`. El dueño reprograma cualquiera de su barbería sin plazo. Cada reprogramación queda en el historial como una entrada de `confirmada` a `confirmada` con su autor y fecha, y una reserva se reprograma como máximo 2 veces: la tercera responde 409 `conflicto`. Una reserva que no está confirmada responde 409 `conflicto`. Un colaborador recibe 403 `prohibido`. Una reserva que no existe, de otra barbería o de otro cliente, un barbero que no existe en la barbería y una persona sin rol en la barbería responden 404 `no-encontrado`; un dato mal escrito, 422 `datos-invalidos`. El aviso por correo al cliente cuando reprograma el dueño y la lista de espera todavía no se aplican.',
    )]
    public function reschedule(RescheduleReservationRequest $request, string $barberia, string $reserva, RescheduleReservation $rescheduleReservation): ReservationResource
    {
        $isOwner = $request->attributes->get(RequestBarbershopContext::ROLE_ATTRIBUTE) === 'owner';

        return new ReservationResource($rescheduleReservation->execute(
            (string) $request->user()?->getAuthIdentifier(),
            $isOwner,
            $reserva,
            $request->startsAt(),
            $request->collaboratorId(),
            $this->clock->now(),
        ));
    }
}
