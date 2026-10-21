<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Src\Reservations\Reservations\Application\UseCases\CreateReservation;
use Src\Reservations\Reservations\Application\UseCases\ListCustomerReservations;
use Src\Reservations\Reservations\Application\UseCases\ListStaffReservations;
use Src\Reservations\Reservations\Infrastructure\Http\Requests\CreateReservationRequest;
use Src\Reservations\Reservations\Infrastructure\Http\Requests\ListMyReservationsRequest;
use Src\Reservations\Reservations\Infrastructure\Http\Requests\ListReservationsRequest;
use Src\Reservations\Reservations\Infrastructure\Http\Resources\ReservationListItemResource;
use Src\Reservations\Reservations\Infrastructure\Http\Resources\ReservationResource;
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
        description: 'Reserva el espacio de un `barbero` que empieza en `inicio` para un `servicio`, a nombre de la persona de la sesión. Vuelve a validar la disponibilidad en ese instante, con bloqueo del barbero, así que dos peticiones por el mismo espacio dejan exactamente una reserva. Sin una política de pago todavía, la reserva queda `confirmada` de inmediato y guarda el precio, la duración y el plazo de cancelación (24 horas, provisional) vigentes. Una persona sin rol en la barbería queda como cliente con su primera reserva; si la reserva falla, no queda ninguna membresía. El dueño o un colaborador de esa barbería recibe 403 `prohibido`. Si el espacio ya no está disponible responde 409 `espacio-no-disponible` y el miembro `alternativas` trae hasta 3 espacios del mismo día, barbero y servicio, los de inicio más próximo al pedido, con `barbero`, `inicio` y `fin`. Si la persona ya tiene una reserva confirmada que se solapa en la barbería, responde 409 `conflicto`. Un servicio o un barbero inexistente, inactivo o de otra barbería responde 404 `no-encontrado`, igual que una barbería inexistente; un dato mal escrito, 422 `datos-invalidos` con el campo.',
    )]
    public function store(CreateReservationRequest $request, CreateReservation $createReservation): JsonResponse
    {
        $reservation = $createReservation->execute($request->toCommand(), $this->clock->now());

        return new ReservationResource($reservation)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** List the reservations the person made in the barbershop. */
    #[Endpoint(
        title: 'Listar mis reservas',
        description: 'Lista, de la de inicio más próximo a la más lejana, las reservas que la persona de la sesión hizo en la barbería, con el nombre del `servicio` y del `barbero`. Se puede filtrar por `dia` (fecha de Costa Rica) y por `estado`. Una persona que todavía no reservó ahí recibe la lista vacía, no un error. El dueño o un colaborador de esa barbería recibe 403 `prohibido`: su vista es `GET /reservas`. Va paginada con `pagina` y `por_pagina` (hasta 50, 15 por defecto) y `meta` trae el total.',
    )]
    public function mine(ListMyReservationsRequest $request, ListCustomerReservations $listReservations): AnonymousResourceCollection
    {
        $page = $listReservations->execute((string) $request->user()?->getAuthIdentifier(), $request->search(), $request->page());

        return ReservationListItemResource::collection($page->items)->additional(PageMeta::of($page));
    }

    /** List the reservations of the agenda the owner or the collaborator may see. */
    #[Endpoint(
        title: 'Listar las reservas de la barbería',
        description: 'Lista, de la de inicio más próximo a la más lejana, las reservas que el rol permite ver, con el nombre del `servicio`, del `barbero` y del cliente (`cliente_nombre`; nunca su correo ni su teléfono). El dueño ve las de todos los colaboradores de su barbería y puede filtrar por `colaborador`; un colaborador ve solo las de su propio perfil y, si pide el `colaborador` de otro, recibe el mismo 404 `no-encontrado` de un recurso inexistente. Un colaborador que no existe en la barbería responde 404 también al dueño. Se puede filtrar además por `dia` (fecha de Costa Rica) y por `estado`. Una persona sin rol en la barbería recibe 404 y un cliente, 403 `prohibido`. Va paginada con `pagina` y `por_pagina` (hasta 50, 15 por defecto) y `meta` trae el total.',
    )]
    public function index(ListReservationsRequest $request, ListStaffReservations $listReservations): AnonymousResourceCollection
    {
        $isOwner = $request->attributes->get(RequestBarbershopContext::ROLE_ATTRIBUTE) === 'owner';
        $page = $listReservations->execute((string) $request->user()?->getAuthIdentifier(), $isOwner, $request->search(), $request->page());

        return StaffReservationListItemResource::collection($page->items)->additional(PageMeta::of($page));
    }
}
