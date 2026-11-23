<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Src\Shared\Domain\Contracts\BarbershopFileStorage;
use Src\Shared\Domain\Exceptions\FileOutsideBarbershopException;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Archivos de la barbería', 'Imágenes que suben las barberías, servidas por barbería y sin iniciar sesión.')]
final readonly class BarbershopFileController
{
    /** Types a stored image can have, by the extension the server gave it. */
    private const array CONTENT_TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    /** Receive the storage of the files of the barbershop in course. */
    public function __construct(private BarbershopFileStorage $storage) {}

    /** Stream an image of the barbershop of the URL. */
    #[Endpoint(
        title: 'Obtener una imagen de la barbería',
        description: 'Devuelve el logo, el banner o la imagen de un servicio que la API entregó en `logo`, `banner` o `imagen`. Es pública porque son datos públicos de la barbería (MT-08, SV-07). Solo sirve archivos de la carpeta de la barbería de la ruta: la ruta de otra barbería, un archivo inexistente o un nombre que no es el que genera la API responden el mismo 404 `no-encontrado`. El nombre del archivo cambia con cada subida, así que la respuesta se puede guardar en caché sin vencimiento.',
    )]
    public function show(string $barberia, string $carpeta, string $archivo): StreamedResponse
    {
        $stream = $this->storage->open('barbershops/'.strtolower($barberia).'/'.$carpeta.'/'.$archivo);
        $type = self::CONTENT_TYPES[strtolower(pathinfo($archivo, PATHINFO_EXTENSION))] ?? null;

        if ($stream === null || $type === null) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw FileOutsideBarbershopException::create();
        }

        return new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
