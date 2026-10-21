<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Src\Reservations\Reservations\Application\UseCases\CreateReservation;
use Src\Reservations\Reservations\Infrastructure\Http\Requests\CreateReservationRequest;
use Src\Reservations\Reservations\Infrastructure\Http\Resources\ReservationResource;
use Src\Shared\Domain\Contracts\Clock;

#[Group('Reservas', 'Reservar el espacio de un barbero.')]
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
}
