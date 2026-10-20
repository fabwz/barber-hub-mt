<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Src\Identity\Users\Application\UseCases\IssueToken;
use Src\Identity\Users\Application\UseCases\RevokeAllTokens;
use Src\Identity\Users\Application\UseCases\RevokeCurrentToken;
use Src\Identity\Users\Infrastructure\Http\Requests\IssueTokenRequest;
use Src\Identity\Users\Infrastructure\Http\Resources\TokenResource;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;

#[Group('Sesiones', 'Ingreso con correo y contraseña y cierre de sesión mediante tokens de acceso.')]
final readonly class TokenController
{
    /** Log in with email and password and answer a bearer token. */
    #[Endpoint(
        title: 'Ingresar con correo y contraseña',
        description: 'Devuelve un token Bearer, que se envía en el encabezado `Authorization`. El token identifica a la persona, no su rol: el rol se verifica en cada petición según la barbería. Responde 401 `credenciales-invalidas` si el correo no existe, la contraseña no coincide o la cuenta está desactivada, sin indicar cuál dato falló. Tras 5 intentos fallidos seguidos desde la misma dirección y con el mismo correo, responde 429 `demasiadas-solicitudes` durante 15 minutos, contados desde el quinto fallo, con el encabezado `Retry-After` en segundos y los minutos para reintentar en el detalle; en ese lapso rechaza incluso la contraseña correcta.',
    )]
    public function store(IssueTokenRequest $request, IssueToken $issueToken): JsonResponse
    {
        return new TokenResource($issueToken->execute($request->toCommand()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** Revoke the token the request was made with. */
    #[Endpoint(
        title: 'Cerrar la sesión en este dispositivo',
        description: 'Revoca el token con el que se hace la petición. Desde ese momento el token responde 401 `no-autenticado`.',
    )]
    public function destroyCurrent(#[CurrentUser] UserModel $user, RevokeCurrentToken $revokeCurrentToken): Response
    {
        $revokeCurrentToken->execute($user->id, (string) $user->currentAccessToken()->getKey());

        return response()->noContent();
    }

    /** Revoke every token of the authenticated person. */
    #[Endpoint(
        title: 'Cerrar la sesión en todos los dispositivos',
        description: 'Revoca todos los tokens de la persona, incluido el de la petición.',
    )]
    public function destroyAll(#[CurrentUser] UserModel $user, RevokeAllTokens $revokeAllTokens): Response
    {
        $revokeAllTokens->execute($user->id);

        return response()->noContent();
    }
}
