<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('General')]
final readonly class ApiIndexController
{
    /** Describe the API and the version being served. */
    #[Endpoint(title: 'Identificar la API y su versión')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'nombre' => config('app.name'),
            'version' => 'v1',
        ]);
    }
}
