<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\OpenApi;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Server;

final readonly class RelativeServerTransformer implements DocumentTransformer
{
    /** Point the contract to a host-independent server path. */
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $document->servers = [Server::make('/'.$context->config->get('api_path'))];
    }
}
