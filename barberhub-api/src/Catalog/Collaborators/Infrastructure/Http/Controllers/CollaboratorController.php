<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Src\Catalog\Collaborators\Application\UseCases\CreateCollaboratorWithAccount;
use Src\Catalog\Collaborators\Application\UseCases\CreateOwnCollaboratorProfile;
use Src\Catalog\Collaborators\Application\UseCases\GetCollaborator;
use Src\Catalog\Collaborators\Application\UseCases\ListCollaborators;
use Src\Catalog\Collaborators\Application\UseCases\ReplaceSchedule;
use Src\Catalog\Collaborators\Application\UseCases\ReplaceServices;
use Src\Catalog\Collaborators\Application\UseCases\SetCollaboratorActive;
use Src\Catalog\Collaborators\Domain\Exceptions\ScheduleEntryException;
use Src\Catalog\Collaborators\Domain\Exceptions\UnknownServiceException;
use Src\Catalog\Collaborators\Domain\ValueObjects\ScheduleSection;
use Src\Catalog\Collaborators\Infrastructure\Http\Requests\ReplaceScheduleRequest;
use Src\Catalog\Collaborators\Infrastructure\Http\Requests\ReplaceServicesRequest;
use Src\Catalog\Collaborators\Infrastructure\Http\Requests\StoreCollaboratorRequest;
use Src\Catalog\Collaborators\Infrastructure\Http\Requests\UpdateCollaboratorRequest;
use Src\Catalog\Collaborators\Infrastructure\Http\Resources\CollaboratorResource;

#[Group('Colaboradores', 'Personal con agenda propia: sus servicios, su horario semanal y sus pausas. Solo el dueño de la barbería los administra.')]
final readonly class CollaboratorController
{
    /** List the collaborators of the barbershop. */
    #[Endpoint(
        title: 'Listar los colaboradores',
        description: 'Trae a todos los colaboradores, activos o no, del más antiguo al más nuevo, con su nombre y correo. Solo el dueño: un colaborador recibe 403 `prohibido` y quien no tiene rol en la barbería, 404 `no-encontrado`.',
    )]
    public function index(ListCollaborators $listCollaborators): AnonymousResourceCollection
    {
        return CollaboratorResource::collection($listCollaborators->execute());
    }

    /** Show one collaborator of the barbershop. */
    #[Endpoint(
        title: 'Consultar un colaborador',
        description: 'Trae el perfil con sus servicios, turnos y pausas. Responde 404 `no-encontrado` si el colaborador no existe en la barbería. Solo el dueño.',
    )]
    public function show(Request $request, GetCollaborator $getCollaborator): CollaboratorResource
    {
        // Route parameters reach the method by position and {barberia} comes first, so the collaborator is read by name.
        return new CollaboratorResource($getCollaborator->execute((string) $request->route('colaborador')));
    }

    /** Create a collaborator together with their account. */
    #[Endpoint(
        title: 'Crear un colaborador con su cuenta',
        description: 'Crea en una sola operación la cuenta (nombre, correo y contraseña temporal), el rol de colaborador en la barbería y el perfil, activo y sin servicios ni horario. La contraseña temporal debe tener al menos 8 caracteres con mayúscula, minúscula, número y signo especial, y nunca aparece en una respuesta. Un correo que ya tiene cuenta responde 422 `datos-invalidos` (el error de correo repetido de siempre); vincular una cuenta existente es la invitación (RF-06). Solo el dueño.',
    )]
    public function store(StoreCollaboratorRequest $request, CreateCollaboratorWithAccount $createCollaborator): JsonResponse
    {
        return new CollaboratorResource($createCollaborator->execute($request->toCommand()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** Give the owner a collaborator profile. */
    #[Endpoint(
        title: 'Crear mi propio perfil de colaborador',
        description: 'Crea el perfil de colaborador de la persona autenticada, que debe ser dueña de la barbería, sin cuenta nueva: conserva el rol de dueño y no recibe otra membresía. Responde 409 `conflicto` si ya tiene perfil. Un colaborador recibe 403 `prohibido`.',
    )]
    public function storeOwn(Request $request, CreateOwnCollaboratorProfile $createOwnProfile): JsonResponse
    {
        /** @var string $ownerId */
        $ownerId = $request->user()?->getAuthIdentifier();

        return new CollaboratorResource($createOwnProfile->execute($ownerId))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** Replace the services a collaborator offers. */
    #[Endpoint(
        title: 'Cambiar los servicios que ofrece un colaborador',
        description: 'Reemplaza la lista completa. Un colaborador solo se reserva para los servicios que tiene habilitados (CO-01). Un servicio que no existe en la barbería responde 422 `datos-invalidos` con `errores` en `servicios.N`. Solo el dueño.',
    )]
    public function updateServices(ReplaceServicesRequest $request, ReplaceServices $replaceServices): CollaboratorResource
    {
        try {
            return new CollaboratorResource($replaceServices->execute($request->toCommand((string) $request->route('colaborador'))));
        } catch (UnknownServiceException $exception) {
            throw ValidationException::withMessages(['servicios.'.$exception->index() => [__($exception->translationKey())]]);
        }
    }

    /** Replace the weekly schedule of a collaborator. */
    #[Endpoint(
        title: 'Definir el horario semanal de un colaborador',
        description: 'Reemplaza los turnos y las pausas completos. Cada turno debe caer dentro de las franjas del horario de la barbería de ese día (se unen las franjas contiguas), cada pausa dentro de un turno del mismo día, y ni los turnos ni las pausas del mismo día se traslapan (HO-02). Lo que no cumple responde 422 `datos-invalidos` con `errores` en `turnos.N` o `pausas.N`, la posición del elemento rechazado. Solo el dueño.',
    )]
    public function updateSchedule(ReplaceScheduleRequest $request, ReplaceSchedule $replaceSchedule): CollaboratorResource
    {
        try {
            return new CollaboratorResource($replaceSchedule->execute($request->toCommand((string) $request->route('colaborador'))));
        } catch (ScheduleEntryException $exception) {
            $list = $exception->section() === ScheduleSection::Shifts ? 'turnos' : 'pausas';

            throw ValidationException::withMessages(["{$list}.{$exception->index()}" => [__($exception->translationKey())]]);
        }
    }

    /** Activate or deactivate a collaborator. */
    #[Endpoint(
        title: 'Activar o desactivar un colaborador',
        description: 'Un colaborador inactivo no recibe reservas y, si es colaborador común, su cuenta deja de entrar a la barbería; el dueño con perfil de colaborador conserva siempre su rol. Aún no se comprueban las reservas futuras confirmadas (CO-03), que llegan con las reservas. Solo el dueño.',
    )]
    public function update(UpdateCollaboratorRequest $request, SetCollaboratorActive $setActive): CollaboratorResource
    {
        return new CollaboratorResource($setActive->execute((string) $request->route('colaborador'), $request->boolean('activo')));
    }
}
