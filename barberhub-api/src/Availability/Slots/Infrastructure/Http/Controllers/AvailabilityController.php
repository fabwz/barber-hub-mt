<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Src\Availability\Slots\Application\UseCases\SearchAvailability;
use Src\Availability\Slots\Infrastructure\Http\Requests\AvailabilityRequest;
use Src\Availability\Slots\Infrastructure\Http\Resources\AvailabilityResource;
use Src\Shared\Domain\Contracts\Clock;

#[Group('Disponibilidad', 'Espacios libres de una barbería para reservar, sin iniciar sesión.')]
final readonly class AvailabilityController
{
    /** Receive the port that tells the current instant. */
    public function __construct(private Clock $clock) {}

    /** Show the free spaces of a service on a day. */
    #[Endpoint(
        title: 'Consultar la disponibilidad',
        description: 'Trae los espacios libres del `servicio` en la `fecha` (AAAA-MM-DD), agrupados por barbero: los del `barbero` indicado o, si se omite, los de todos los que ofrecen el servicio. Cada espacio trae `inicio` y `fin` en ISO 8601 con el desfase de Costa Rica (-06:00) y el `barbero`. Incluye el servicio con su duración y su precio con el IVA de 13 % desglosado en `base` e `iva`. Ningún espacio empieza con menos de 60 minutos de anticipación ni a más de 30 días; una fecha fuera de ese plazo devuelve la lista vacía. Si la `fecha` no tiene espacios, `dias_siguientes` trae hasta 3 días posteriores que sí los tienen, dentro de ese plazo. La asignación de "cualquiera disponible" y la lista de espera no están disponibles todavía. Una `fecha` con formato inválido responde 422 `datos-invalidos` con el campo; un servicio inexistente, inactivo o de otra barbería y un barbero que no es de la barbería responden 404 `no-encontrado`; un barbero inactivo o que no ofrece el servicio, 422 `datos-invalidos`.',
    )]
    public function index(AvailabilityRequest $request, SearchAvailability $searchAvailability): AvailabilityResource
    {
        return new AvailabilityResource($searchAvailability->execute(
            $request->serviceId(),
            $request->day(),
            $request->collaboratorId(),
            $this->clock->now(),
        ));
    }
}
