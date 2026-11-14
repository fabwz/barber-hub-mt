<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Src\Vouchers\VoucherDefinitions\Application\UseCases\CreateVoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Application\UseCases\GetVoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Application\UseCases\ListVoucherDefinitions;
use Src\Vouchers\VoucherDefinitions\Application\UseCases\UpdateVoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Domain\Exceptions\UnknownCoveredServiceException;
use Src\Vouchers\VoucherDefinitions\Infrastructure\Http\Requests\StoreVoucherDefinitionRequest;
use Src\Vouchers\VoucherDefinitions\Infrastructure\Http\Requests\UpdateVoucherDefinitionRequest;
use Src\Vouchers\VoucherDefinitions\Infrastructure\Http\Resources\VoucherDefinitionResource;
use Symfony\Component\HttpFoundation\Response;

#[Group('Bonos de la barbería', 'Administración de los bonos que vende la barbería, por su dueño.')]
final readonly class VoucherDefinitionController
{
    /** List every voucher of the barbershop. */
    #[Endpoint(
        title: 'Listar los bonos de la barbería',
        description: 'Trae todos los bonos, a la venta o retirados, ordenados por nombre, con sus sesiones, su precio con el IVA de 13 % incluido, su vigencia en días y los servicios que cubren. Solo el dueño: un colaborador o un cliente reciben 403 `prohibido` y quien no tiene rol en la barbería, 404 `no-encontrado`.',
    )]
    public function index(ListVoucherDefinitions $listVoucherDefinitions): AnonymousResourceCollection
    {
        return VoucherDefinitionResource::collection($listVoucherDefinitions->execute());
    }

    /** Define a voucher of the barbershop. */
    #[Endpoint(
        title: 'Crear un bono',
        description: 'Crea un bono a la venta con nombre, sesiones (al menos 1), precio en colones enteros mayores que cero con el IVA incluido, vigencia en días (al menos 1) y los servicios que cubre: al menos uno, sin repetir y todos de esta barbería. Un valor fuera de rango, una lista vacía o repetida, o un servicio que no existe en la barbería responde 422 `datos-invalidos` y `errores` indica el campo (`servicios.N` para el servicio). Solo el dueño.',
    )]
    public function store(StoreVoucherDefinitionRequest $request, CreateVoucherDefinition $createVoucherDefinition): JsonResponse
    {
        try {
            $definition = $createVoucherDefinition->execute($request->toCommand());
        } catch (UnknownCoveredServiceException $exception) {
            throw ValidationException::withMessages(['servicios.'.$exception->index() => [__($exception->translationKey())]]);
        }

        return new VoucherDefinitionResource($definition)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED)
            ->header('Location', $request->url().'/'.$definition->id->value);
    }

    /** Read a voucher of the barbershop. */
    #[Endpoint(
        title: 'Ver un bono',
        description: 'Trae el bono con los servicios que cubre, esté a la venta o retirado. Responde 404 `no-encontrado` si no existe en la barbería. Solo el dueño.',
    )]
    public function show(string $barberia, string $bono, GetVoucherDefinition $getVoucherDefinition): VoucherDefinitionResource
    {
        return new VoucherDefinitionResource($getVoucherDefinition->execute($bono));
    }

    /** Change, withdraw or put back on sale a voucher of the barbershop. */
    #[Endpoint(
        title: 'Editar, retirar o reactivar un bono',
        description: 'Cambia solo los campos que vienen. `activo: false` retira el bono de la venta y `activo: true` lo vuelve a poner; retirarlo o cambiarle el precio no toca los bonos que los clientes ya compraron, que conservan su saldo, su vigencia y lo que pagaron (BO-01). Si el bono ya tiene ventas, sus `sesiones`, su `vigencia_dias` y sus `servicios` no se cambian: pedir un valor distinto responde 409 `bono-con-ventas`, y solo se pueden editar `nombre` y `precio` o retirarlo. Sin ventas se puede editar todo. Un servicio que no existe en la barbería responde 422 `datos-invalidos`, y un bono que no existe en ella, 404 `no-encontrado`. Solo el dueño.',
    )]
    public function update(UpdateVoucherDefinitionRequest $request, UpdateVoucherDefinition $updateVoucherDefinition): VoucherDefinitionResource
    {
        try {
            return new VoucherDefinitionResource($updateVoucherDefinition->execute($request->toCommand()));
        } catch (UnknownCoveredServiceException $exception) {
            throw ValidationException::withMessages(['servicios.'.$exception->index() => [__($exception->translationKey())]]);
        }
    }
}
