<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\OpenApi;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;

/** Scramble cannot say that the items of a list are UUIDs, so the properties named here get the format on their items. */
final readonly class UuidListItemsTransformer implements DocumentTransformer
{
    /** Schema name and the list property whose items are UUID strings; the format moves from the list to its items. */
    private const array PROPERTIES = [
        'Bono' => 'servicios',
    ];

    /** Mark the items of the listed properties as `string` with the `uuid` format. */
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        foreach (self::PROPERTIES as $schemaName => $property) {
            if (! $document->components->hasSchema($schemaName)) {
                continue;
            }

            $type = $document->components->getSchema($schemaName)->type;
            $list = $type instanceof ObjectType && $type->hasProperty($property) ? $type->getProperty($property) : null;

            if ($list instanceof ArrayType) {
                $list->format = '';
                $list->items->format('uuid');
            }
        }
    }
}
