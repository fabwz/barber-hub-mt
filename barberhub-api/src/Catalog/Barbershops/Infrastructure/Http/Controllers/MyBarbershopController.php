<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Src\Catalog\Barbershops\Application\UseCases\ListMyBarbershops;
use Src\Catalog\Barbershops\Infrastructure\Http\Resources\MyBarbershopResource;

#[Group('Mis barberías', 'Barberías donde la persona autenticada tiene un rol.')]
final readonly class MyBarbershopController
{
    /** List the barbershops where the authenticated person holds an active role. */
    #[Endpoint(
        title: 'Listar mis barberías',
        description: 'Devuelve las barberías donde la persona autenticada tiene una membresía activa, ordenadas por `nombre`, con su `id` (el que las demás rutas usan en `{barberia}`), el `enlace` público y el `rol` que tiene en cada una: `dueno`, `colaborador` o `cliente`. Una persona puede tener roles distintos en barberías distintas. Una membresía inactiva, como la de un colaborador desactivado, no aparece, y solo salen las membresías de la propia persona. Sin ninguna, `data` es una lista vacía. No lleva `{barberia}` porque no hay barbería en curso: el cliente guarda la elegida y la envía en las demás rutas, donde el servidor verifica la membresía (404 si no existe). Responde 401 `no-autenticado` sin token.',
    )]
    public function index(Request $request, ListMyBarbershops $listMyBarbershops): AnonymousResourceCollection
    {
        /** @var string $userId */
        $userId = $request->user()?->getAuthIdentifier();

        return MyBarbershopResource::collection($listMyBarbershops->execute($userId));
    }
}
