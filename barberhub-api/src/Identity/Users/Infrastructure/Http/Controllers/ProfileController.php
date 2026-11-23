<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Container\Attributes\CurrentUser;
use Src\Identity\Users\Application\UseCases\ShowAccount;
use Src\Identity\Users\Application\UseCases\UpdateAccount;
use Src\Identity\Users\Infrastructure\Http\Requests\UpdateAccountRequest;
use Src\Identity\Users\Infrastructure\Http\Resources\ProfileResource;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;

#[Group('Cuentas', 'Registro de cuentas de personas que usan BarberHub.')]
final readonly class ProfileController
{
    /** Show the personal data of the authenticated person. */
    #[Endpoint(
        title: 'Ver mi cuenta',
        description: 'Devuelve el nombre, el teléfono y el correo de la persona autenticada (RF-45), y `contrasena_temporal`, que indica si todavía debe cambiar la contraseña temporal (UP-04) para que el cliente la lleve al cambio obligatorio sin esperar un 403. Es una de las pocas rutas que una contraseña temporal permite. Solo muestra la cuenta de la sesión, sea cliente, colaborador o dueño.',
    )]
    public function show(#[CurrentUser] UserModel $user, ShowAccount $showAccount): ProfileResource
    {
        return new ProfileResource($showAccount->execute($user->id));
    }

    /** Correct the personal data of the authenticated person. */
    #[Endpoint(
        title: 'Corregir mi cuenta',
        description: 'Corrige el nombre, el teléfono y el correo de la propia cuenta (RF-45, CL-03); los tres son obligatorios y no se acepta barbería ni rol en el cuerpo. Responde 422 `datos-invalidos` si un dato no es válido o si otra cuenta ya usa el correo (`errores.correo`); enviar el correo propio sin cambio no es un conflicto. No toca la contraseña ni el indicador de contraseña temporal, así que una cuenta con contraseña temporal recibe 403 `contrasena-temporal` hasta que la cambia. El correo nuevo es el que sirve para ingresar: el anterior deja de abrir la cuenta.',
    )]
    public function update(UpdateAccountRequest $request, #[CurrentUser] UserModel $user, UpdateAccount $updateAccount): ProfileResource
    {
        return new ProfileResource($updateAccount->execute($request->toCommand($user->id)));
    }
}
