<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Src\Catalog\Barbershops\Application\UseCases\ChangeBarbershopImage;
use Src\Catalog\Barbershops\Infrastructure\Http\Requests\StoreBannerRequest;
use Src\Catalog\Barbershops\Infrastructure\Http\Requests\StoreLogoRequest;
use Src\Catalog\Barbershops\Infrastructure\Http\Resources\BarbershopImagesResource;

#[Group('Imágenes de la barbería', 'Subir o reemplazar el logo y el banner de la barbería, a cargo del dueño.')]
final readonly class BarbershopImageController
{
    /** Upload or replace the logo. */
    #[Endpoint(
        title: 'Subir o reemplazar el logo',
        description: 'Recibe `multipart/form-data` con el archivo en `logo`. Acepta JPG, PNG y WebP de hasta 2 MB; el tipo se lee del contenido del archivo, de modo que un PDF o un ejecutable con nombre `.jpg` se rechaza. Un archivo inválido responde 422 `datos-invalidos` y `errores.logo` dice por qué. Guarda el archivo en el espacio de la barbería, borra el logo anterior y responde las direcciones de `logo` y `banner`. Se usa `POST` porque un `PUT` multipart no llega completo al servidor. Solo el dueño: un colaborador o un cliente recibe 403 `prohibido` y quien no tiene rol en la barbería, 404 `no-encontrado`.',
    )]
    public function logo(StoreLogoRequest $request, ChangeBarbershopImage $changeBarbershopImage): BarbershopImagesResource
    {
        return new BarbershopImagesResource($changeBarbershopImage->execute($request->toCommand()));
    }

    /** Upload or replace the banner. */
    #[Endpoint(
        title: 'Subir o reemplazar el banner',
        description: 'Recibe `multipart/form-data` con el archivo en `banner`. Acepta JPG, PNG y WebP de hasta 2 MB que midan al menos 1.200 × 400 píxeles; uno menor responde 422 `datos-invalidos` con `errores.banner` que lo explica. El tipo se lee del contenido del archivo, no de su nombre. Guarda el archivo en el espacio de la barbería, borra el banner anterior y responde las direcciones de `logo` y `banner`. Solo el dueño: un colaborador o un cliente recibe 403 `prohibido` y quien no tiene rol en la barbería, 404 `no-encontrado`.',
    )]
    public function banner(StoreBannerRequest $request, ChangeBarbershopImage $changeBarbershopImage): BarbershopImagesResource
    {
        return new BarbershopImagesResource($changeBarbershopImage->execute($request->toCommand()));
    }
}
