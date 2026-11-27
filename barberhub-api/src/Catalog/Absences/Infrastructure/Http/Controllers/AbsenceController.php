<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Src\Catalog\Absences\Application\UseCases\DeleteAbsence;
use Src\Catalog\Absences\Application\UseCases\ListAbsences;
use Src\Catalog\Absences\Application\UseCases\RegisterAbsence;
use Src\Catalog\Absences\Infrastructure\Http\Requests\StoreAbsenceRequest;
use Src\Catalog\Absences\Infrastructure\Http\Resources\AbsenceResource;
use Src\Shared\Domain\Contracts\Clock;

#[Group('Bloqueos de agenda', 'Ausencias de un colaborador (vacaciones, incapacidad): mientras duran, sus espacios dejan de ofrecerse.')]
final readonly class AbsenceController
{
    /** Receive the port that tells the current instant. */
    public function __construct(private Clock $clock) {}

    /** List the absences of the barbershop that have not ended. */
    #[Endpoint(
        title: 'Listar los bloqueos vigentes',
        description: 'Trae los bloqueos de agenda de todos los colaboradores que todavía no terminan, del más próximo al más lejano. Solo el dueño: un colaborador o un cliente recibe 403 `prohibido` y quien no tiene rol en la barbería, 404 `no-encontrado`.',
    )]
    public function index(ListAbsences $listAbsences): AnonymousResourceCollection
    {
        return AbsenceResource::collection($listAbsences->execute($this->clock->now()));
    }

    /** Block the agenda of a collaborator. */
    #[Endpoint(
        title: 'Bloquear la agenda de un colaborador',
        description: 'Bloquea al `colaborador` desde `fecha_desde` hasta `fecha_hasta` (ambas incluidas, hora de Costa Rica). Sin horas cubre los días completos; con `hora_desde` y `hora_hasta` (juntas) empieza a esa hora el primer día y termina a esa hora el último. Un rango que ya terminó o que termina antes de empezar responde 422 `datos-invalidos`; un colaborador inactivo, 409 `conflicto`; uno que no es de la barbería, 404 `no-encontrado`. Si el rango cae sobre reservas confirmadas o temporales vigentes del colaborador no se guarda y responde 409 `ausencia-con-reservas` con las más próximas en `reservas` y cuántas son en `total_reservas`: el dueño las reprograma o las cancela con las acciones de las reservas y vuelve a guardar. Solo el dueño.',
    )]
    public function store(StoreAbsenceRequest $request, RegisterAbsence $registerAbsence): JsonResponse
    {
        return new AbsenceResource($registerAbsence->execute($request->toCommand(), $this->clock->now()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** Remove an absence so its spaces are offered again. */
    #[Endpoint(
        title: 'Quitar un bloqueo',
        description: 'Los espacios del colaborador vuelven a ofrecerse según su horario. Responde 404 `no-encontrado` si el bloqueo no existe en la barbería. Solo el dueño.',
    )]
    public function destroy(Request $request, DeleteAbsence $deleteAbsence): Response
    {
        // Route parameters reach the method by position and {barberia} comes first, so the absence is read by name.
        $deleteAbsence->execute((string) $request->route('ausencia'));

        return response()->noContent();
    }
}
