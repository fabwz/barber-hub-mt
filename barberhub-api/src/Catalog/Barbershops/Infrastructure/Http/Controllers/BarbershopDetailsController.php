<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Src\Catalog\Barbershops\Application\UseCases\GetBarbershopDetails;
use Src\Catalog\Barbershops\Application\UseCases\UpdateBarbershop;
use Src\Catalog\Barbershops\Infrastructure\Http\Requests\UpdateBarbershopRequest;
use Src\Catalog\Barbershops\Infrastructure\Http\Resources\BarbershopDetailsResource;

#[Group('Datos de la barbería', 'Nombre, ciudad, teléfono, dirección, correo de contacto y redes sociales, a cargo del dueño.')]
final readonly class BarbershopDetailsController
{
    /** Show the data of the barbershop that its owner edits. */
    #[Endpoint(
        title: 'Consultar los datos de la barbería',
        description: 'Trae `nombre`, `ciudad`, `telefono`, el `enlace` público, la `direccion`, el `correo_contacto` y el objeto `redes` con `whatsapp`, `instagram`, `facebook` y `tiktok`. Lo que no se ha indicado viene como `null`. Solo el dueño: un colaborador recibe 403 `prohibido` y quien no tiene rol en la barbería, 404 `no-encontrado`.',
    )]
    public function show(GetBarbershopDetails $getBarbershopDetails): BarbershopDetailsResource
    {
        return new BarbershopDetailsResource($getBarbershopDetails->execute());
    }

    /** Change the data of the barbershop. */
    #[Endpoint(
        title: 'Cambiar los datos de la barbería',
        description: 'Cambia solo los campos que vienen: `nombre`, `ciudad`, `telefono`, `direccion` (hasta 200 caracteres), `correo_contacto` (correo válido de hasta 150; es el del negocio, distinto del correo de la cuenta) y `redes`. Cada red es un enlace de hasta 300 caracteres que empieza por `https://` y es del dominio de su red: `whatsapp` (`wa.me` o `api.whatsapp.com`), `instagram` (`instagram.com`), `facebook` (`facebook.com` o `fb.com`) y `tiktok` (`tiktok.com`), con o sin `www.`. En `direccion`, `correo_contacto` y cada red, `null` o vacío borra el dato. Un valor inválido responde 422 `datos-invalidos` y `errores` indica el campo (por ejemplo `redes.instagram`). Cambiar el nombre no cambia el `enlace` público (MT-07). Solo el dueño.',
    )]
    public function update(UpdateBarbershopRequest $request, UpdateBarbershop $updateBarbershop): BarbershopDetailsResource
    {
        return new BarbershopDetailsResource($updateBarbershop->execute($request->toCommand()));
    }
}
