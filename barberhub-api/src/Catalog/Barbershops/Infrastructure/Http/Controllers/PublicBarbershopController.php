<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Src\Catalog\Barbershops\Application\UseCases\GetBarbershopByLink;
use Src\Catalog\Barbershops\Infrastructure\Http\Resources\PublicBarbershopResource;

#[Group('Enlaces públicos', 'Consulta de una barbería por su enlace, sin iniciar sesión.')]
final readonly class PublicBarbershopController
{
    /** Show the public data of the barbershop a link opens. */
    #[Endpoint(
        title: 'Consultar una barbería por su enlace',
        description: 'Devuelve el `id` de la barbería, que es el que usan las demás rutas en `{barberia}`, y sus datos públicos. El enlace son minúsculas, dígitos y guiones. Responde 404 `no-encontrado` con el mensaje "Esta barbería no existe" si ninguna barbería tiene ese enlace o si el enlace no tiene ese formato.',
    )]
    public function show(string $enlace, GetBarbershopByLink $getBarbershopByLink): PublicBarbershopResource
    {
        return new PublicBarbershopResource($getBarbershopByLink->execute($enlace));
    }
}
