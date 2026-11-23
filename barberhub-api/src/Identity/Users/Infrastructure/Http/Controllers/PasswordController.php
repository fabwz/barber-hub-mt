<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;
use Src\Identity\Users\Application\UseCases\ChangePassword;
use Src\Identity\Users\Infrastructure\Http\Requests\ChangePasswordRequest;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;

#[Group('Cuentas', 'Registro de cuentas de personas que usan BarberHub.')]
final readonly class PasswordController
{
    /** Change the password of the authenticated person. */
    #[Endpoint(
        title: 'Cambiar la contraseña',
        description: 'Cambia la contraseña de la persona autenticada indicando la actual (RF-40). La nueva debe tener al menos 8 caracteres, con mayúscula, minúscula, número y signo especial. Cierra las demás sesiones de la persona y conserva la del token con el que se hace la petición. Responde 422 `datos-invalidos` si la actual no es correcta (`errores.contrasena_actual`) o si la nueva incumple la política (`errores.contrasena_nueva` lista todos los requisitos que faltan) o es igual a la actual (`errores.contrasena_nueva`: «Elija una contraseña distinta de la actual.»; es obligatorio cuando la actual es la temporal). Una cuenta con contraseña temporal (UP-04) solo puede usar esta ruta y las de cierre de sesión: el resto responde 403 `contrasena-temporal` hasta que la cambia.',
    )]
    public function update(ChangePasswordRequest $request, #[CurrentUser] UserModel $user, ChangePassword $changePassword): Response
    {
        $changePassword->execute($request->toCommand($user->id, (string) $user->currentAccessToken()->getKey()));

        return response()->noContent();
    }
}
