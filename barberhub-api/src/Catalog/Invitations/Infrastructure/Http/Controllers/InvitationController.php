<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Src\Catalog\Invitations\Application\UseCases\CancelInvitation;
use Src\Catalog\Invitations\Application\UseCases\InviteCollaborator;
use Src\Catalog\Invitations\Application\UseCases\ListPendingInvitations;
use Src\Catalog\Invitations\Domain\Entities\Invitation;
use Src\Catalog\Invitations\Domain\Exceptions\UnknownServiceException;
use Src\Catalog\Invitations\Infrastructure\Http\Requests\InviteCollaboratorRequest;
use Src\Catalog\Invitations\Infrastructure\Http\Resources\InvitationResource;

#[Group('Invitaciones de colaboradores', 'Invitar por correo a quien se une al personal, ver las invitaciones pendientes y cancelarlas. Solo el dueño de la barbería.')]
final readonly class InvitationController
{
    /** List the pending invitations of the barbershop. */
    #[Endpoint(
        title: 'Listar las invitaciones pendientes',
        description: 'Trae las invitaciones que nadie ha aceptado y cuyo enlace no ha vencido (RF-06), de la más nueva a la más antigua. Las aceptadas, canceladas o vencidas no aparecen. Solo el dueño: un colaborador recibe 403 `prohibido` y quien no tiene rol en la barbería, 404 `no-encontrado`.',
    )]
    public function index(ListPendingInvitations $listInvitations): AnonymousResourceCollection
    {
        return InvitationResource::collection($listInvitations->execute());
    }

    /** Invite an email to join the staff. */
    #[Endpoint(
        title: 'Invitar a un colaborador por correo',
        description: 'Guarda la invitación y deja en la cola el correo con el enlace para crear su contraseña (RF-06): válido 7 días y de un solo uso. El token no se guarda ni se devuelve; solo viaja en el correo. Invitar de nuevo al mismo correo reemplaza la invitación pendiente, y el enlace anterior deja de funcionar. Un servicio que no existe en la barbería responde 422 `datos-invalidos` con `errores` en `servicios.N`. Solo el dueño.',
    )]
    public function store(InviteCollaboratorRequest $request, InviteCollaborator $inviteCollaborator): JsonResponse
    {
        /** @var string $ownerId */
        $ownerId = $request->user()?->getAuthIdentifier();

        try {
            $invitation = $inviteCollaborator->execute($request->toCommand($ownerId));
        } catch (UnknownServiceException $exception) {
            throw ValidationException::withMessages(['servicios.'.$exception->index() => [__($exception->translationKey())]]);
        }

        return new InvitationResource($invitation)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** Cancel a pending invitation. */
    #[Endpoint(
        title: 'Cancelar una invitación pendiente',
        description: 'Borra la invitación: su enlace deja de funcionar al instante (RF-06). Responde 404 `no-encontrado` si no existe en la barbería, ya fue aceptada o ya venció. Solo el dueño.',
    )]
    public function destroy(Request $request, CancelInvitation $cancelInvitation): Response
    {
        // Route parameters reach the method by position and {barberia} comes first, so the invitation is read by name.
        $cancelInvitation->execute((string) $request->route('invitacion'));

        return response()->noContent();
    }
}
