<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Src\Shared\Infrastructure\Http\Requests\ListRequest;

#[SchemaName('ConsultaDirectorio')]
final class ListDirectoryRequest extends ListRequest
{
    /**
     * Validation rules for the page of the directory and its search.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            /** Texto que debe contener el nombre o la ciudad de la barbería. No distingue mayúsculas ni tildes. */
            'buscar' => ['sometimes', 'nullable', 'string', 'max:80'],
        ];
    }

    /** The text to search, or null when none came. */
    public function search(): ?string
    {
        $search = $this->input('buscar');

        return is_string($search) ? $search : null;
    }
}
