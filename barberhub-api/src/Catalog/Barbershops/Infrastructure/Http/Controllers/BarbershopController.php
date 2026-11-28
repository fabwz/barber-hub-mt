<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Src\Catalog\Barbershops\Application\UseCases\RegisterBarbershop;
use Src\Catalog\Barbershops\Infrastructure\Http\Requests\RegisterBarbershopRequest;
use Src\Catalog\Barbershops\Infrastructure\Http\Resources\BarbershopResource;

#[Group('Barberías', 'Alta de barberías y de su dueño.')]
final readonly class BarbershopController
{
    /** Register a barbershop and its owner on behalf of the platform operator. */
    #[Endpoint(
        title: 'Registrar una barbería y su dueño',
        description: 'Solo el operador de plataforma (RF-01, PL-02). Crea la barbería con nombre, ciudad y teléfono de contacto, sin servicios ni horario y con la modalidad de cobro «sin pago», su `enlace` público (derivado del nombre, por ejemplo `pura-navaja`, o `pura-navaja-2` si ya existe; se genera una sola vez y no cambia), su primera cuota de alquiler y el rol de dueño de la persona de `dueno` (nombre, correo y teléfono). Si el correo del dueño no tiene cuenta, la crea con una contraseña temporal que cumple la política, obliga a cambiarla en el primer ingreso y la devuelve **una sola vez** en `dueno.contrasena_temporal`, con `dueno.cuenta_creada: true`; la respuesta no se guarda en caché y la contraseña no queda en ningún registro. Si el correo ya tiene cuenta, esa persona recibe el rol sin cambiar nada de su cuenta: `dueno.cuenta_creada: false` y `dueno.contrasena_temporal: null`. Todo se hace en una sola transacción y queda en el registro técnico con el operador como autor. Responde 401 `no-autenticado` sin token, 403 `prohibido` a toda cuenta que no sea operador, 409 `conflicto` si el correo del dueño es de un operador (un operador no tiene rol en ninguna barbería, PL-01) y 422 `datos-invalidos` si falta un dato o supera su largo máximo (`errores` lista cada campo).',
    )]
    public function store(RegisterBarbershopRequest $request, RegisterBarbershop $registerBarbershop): JsonResponse
    {
        /** @var string $operatorId */
        $operatorId = $request->user()?->getAuthIdentifier();

        return new BarbershopResource($registerBarbershop->execute($request->toCommand($operatorId)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED)
            ->header('Cache-Control', 'no-store');
    }
}
