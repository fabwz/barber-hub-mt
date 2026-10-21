<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Shared\Domain\ValueObjects\PageRequest;

/** Page of any listing; a listing with filters of its own extends it and adds its rules to these. */
class ListRequest extends FormRequest
{
    /**
     * Validation rules for the page of a listing.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** Número de página, desde 1. */
            'pagina' => ['sometimes', 'integer', 'min:1'],
            /** Elementos por página, de 1 a 50. Por defecto, 15. */
            'por_pagina' => ['sometimes', 'integer', 'between:1,'.PageRequest::MAX_SIZE],
        ];
    }

    /** Page asked for, the first one of 15 elements by default. */
    public function page(): PageRequest
    {
        return new PageRequest(
            $this->integer('pagina', 1),
            $this->integer('por_pagina', PageRequest::DEFAULT_SIZE),
        );
    }
}
