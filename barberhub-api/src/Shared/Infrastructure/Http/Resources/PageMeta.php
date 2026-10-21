<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Http\Resources;

use Src\Shared\Domain\ValueObjects\Page;

final class PageMeta
{
    /**
     * Describe the page in the vocabulary of the API, to go next to its data.
     *
     * @param  Page<mixed>  $page
     * @return array{meta: array{pagina: int, por_pagina: int, total: int, ultima_pagina: int}}
     */
    public static function of(Page $page): array
    {
        return ['meta' => [
            'pagina' => $page->request->number,
            'por_pagina' => $page->request->size,
            'total' => $page->total,
            'ultima_pagina' => $page->lastPage(),
        ]];
    }
}
