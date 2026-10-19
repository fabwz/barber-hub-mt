<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Src\Identity\Users\Application\UseCases\RegisterCustomer;
use Src\Identity\Users\Infrastructure\Http\Requests\RegisterCustomerRequest;
use Src\Identity\Users\Infrastructure\Http\Resources\AccountResource;
use Src\Shared\Domain\Contracts\Clock;

#[Group('Cuentas', 'Registro de cuentas de personas que usan BarberHub.')]
final readonly class AccountController
{
    /** Register an account and answer it without the password. */
    #[Endpoint(
        title: 'Registrar una cuenta',
        description: 'Crea una cuenta con nombre, correo, teléfono y contraseña, y guarda la fecha de cada aceptación (términos y privacidad), que se exigen por separado. No crea ninguna membresía: el rol de cliente nace al primer uso en una barbería. Responde 422 `datos-invalidos` si falta un dato, una aceptación o un requisito de la contraseña (`errores.contrasena` lista todos los que faltan), o si el correo ya tiene una cuenta.',
    )]
    public function store(RegisterCustomerRequest $request, RegisterCustomer $registerCustomer, Clock $clock): JsonResponse
    {
        return new AccountResource($registerCustomer->execute($request->toCommand($clock->now())))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
