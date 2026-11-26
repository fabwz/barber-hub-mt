<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Response;
use Src\Identity\Users\Application\UseCases\RequestPasswordReset;
use Src\Identity\Users\Application\UseCases\ResetPassword;
use Src\Identity\Users\Infrastructure\Http\Requests\RequestPasswordResetRequest;
use Src\Identity\Users\Infrastructure\Http\Requests\ResetPasswordRequest;

#[Group('Cuentas', 'Registro de cuentas de personas que usan BarberHub.')]
final readonly class PasswordRecoveryController
{
    /** Ask for the link to reset a password; the answer is the same whether or not the email has an account. */
    #[Endpoint(
        title: 'Solicitar el enlace para restablecer la contraseña',
        description: 'Ruta pública (RF-41). Responde siempre 202 sin cuerpo, exista o no una cuenta con ese correo: la respuesta no permite saberlo. Si la cuenta existe y está activa, el enlace sale por correo desde la cola; es válido 60 minutos, se usa una sola vez y reemplaza al anterior (SE-06). Se aceptan como máximo 5 solicitudes por hora por correo y 5 por dirección IP; la sexta responde 429 `demasiadas-solicitudes` con `Retry-After` en segundos.',
    )]
    public function store(RequestPasswordResetRequest $request, RequestPasswordReset $requestPasswordReset): Response
    {
        $requestPasswordReset->execute($request->toCommand());

        return response()->noContent(Response::HTTP_ACCEPTED);
    }

    /** Set a new password with the token of the link. */
    #[Endpoint(
        title: 'Restablecer la contraseña con el enlace',
        description: 'Ruta pública (RF-41). Recibe el `token` del enlace y la `contrasena_nueva`, que debe tener al menos 8 caracteres, con mayúscula, minúscula, número y signo especial (SE-07); `errores.contrasena_nueva` lista todos los requisitos que faltan y el enlace no se gasta. Un enlace desconocido, vencido (a los 60 minutos) o ya usado responde 422 `datos-invalidos` con `errores.token`, sin decir cuál de los tres casos es. Al terminar cierra todas las sesiones y todos los tokens de la cuenta, y la contraseña deja de ser temporal. Responde 204.',
    )]
    public function update(ResetPasswordRequest $request, ResetPassword $resetPassword): Response
    {
        $resetPassword->execute($request->toCommand());

        return response()->noContent();
    }
}
