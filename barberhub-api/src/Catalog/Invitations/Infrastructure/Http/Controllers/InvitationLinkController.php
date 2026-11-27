<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Response;
use Src\Catalog\Invitations\Application\UseCases\AcceptInvitation;
use Src\Catalog\Invitations\Application\UseCases\DescribeInvitation;
use Src\Catalog\Invitations\Infrastructure\Http\Requests\AcceptInvitationRequest;
use Src\Catalog\Invitations\Infrastructure\Http\Requests\InvitationLinkRequest;
use Src\Catalog\Invitations\Infrastructure\Http\Resources\InvitationDetailsResource;

#[Group('Aceptar una invitación', 'Rutas públicas, sin sesión, de quien abre el enlace de la invitación que recibió por correo: ver a qué invita y crear su contraseña.')]
final readonly class InvitationLinkController
{
    /** Tell what a link invites to, without using it. */
    #[Endpoint(
        title: 'Consultar una invitación con su enlace',
        description: 'Ruta pública, sin sesión (RF-06). Recibe el `token` del enlace y responde la barbería que invita, el correo invitado y si ese correo ya tiene cuenta (`cuenta_existente`), para que la pantalla pida solo lo que falta. No gasta el enlace. Un enlace desconocido, vencido, ya usado o cancelado responde 422 `datos-invalidos` con `errores.token`, sin decir cuál de los casos es. Se limita a 10 solicitudes por minuto por dirección IP; la siguiente responde 429 `demasiadas-solicitudes` con `Retry-After`. Es POST para que el token no viaje en la dirección ni quede en los registros.',
    )]
    public function show(InvitationLinkRequest $request, DescribeInvitation $describeInvitation): InvitationDetailsResource
    {
        return new InvitationDetailsResource($describeInvitation->execute($request->token()));
    }

    /** Accept the invitation. */
    #[Endpoint(
        title: 'Aceptar una invitación',
        description: 'Ruta pública, sin sesión (RF-06, UP-04). La barbería sale del propio enlace, nunca del cuerpo. Si el correo no tiene cuenta, `nombre` y `contrasena` son obligatorias y la contraseña debe cumplir SE-07 (`errores.contrasena` lista los requisitos que faltan, y el enlace no se gasta); la cuenta queda activa, con la contraseña que la persona eligió. Si ya tiene cuenta, se le asigna el rol de colaborador sin cambiar su contraseña y `nombre` y `contrasena` se ignoran. En ambos casos queda su perfil de colaborador con los servicios invitados. Un enlace desconocido, vencido, ya usado o cancelado responde 422 `datos-invalidos` con `errores.token`. Si el correo ya tiene un rol en esa barbería responde 409 `conflicto` y el enlace no se gasta (UP-07). Se limita a 10 solicitudes por minuto por dirección IP. Responde 204.',
    )]
    public function accept(AcceptInvitationRequest $request, AcceptInvitation $acceptInvitation): Response
    {
        $acceptInvitation->execute($request->toCommand());

        return response()->noContent();
    }
}
