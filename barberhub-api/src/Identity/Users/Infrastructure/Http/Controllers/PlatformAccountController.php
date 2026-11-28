<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Container\Attributes\CurrentUser;
use Src\Identity\Users\Application\UseCases\ShowAccount;
use Src\Identity\Users\Infrastructure\Http\Resources\PlatformAccountResource;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;

#[Group('Plataforma', 'Operaciones del operador de plataforma: no llevan barbería en la ruta y ningún rol de barbería las abre.')]
final readonly class PlatformAccountController
{
    /** Show the account of the platform operator who asks. */
    #[Endpoint(
        title: 'Ver la cuenta del operador de plataforma',
        description: 'Devuelve el `id`, el nombre y el correo del operador autenticado (RF-55). Solo la abre una cuenta marcada como operador de plataforma, que se crea únicamente por comando (PL-01): un dueño, un colaborador o un cliente reciben 403 `prohibido`. Una cuenta con contraseña temporal recibe 403 `contrasena-temporal` hasta que la cambia (RF-40). El operador no tiene barbería ni rol: en cualquier ruta de una barbería recibe 404, como quien no tiene rol.',
    )]
    public function show(#[CurrentUser] UserModel $user, ShowAccount $showAccount): PlatformAccountResource
    {
        return new PlatformAccountResource($showAccount->execute($user->id));
    }
}
