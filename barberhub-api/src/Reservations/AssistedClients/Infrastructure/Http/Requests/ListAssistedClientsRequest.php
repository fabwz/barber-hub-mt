<?php

declare(strict_types=1);

namespace Src\Reservations\AssistedClients\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;

#[SchemaName('BuscarClientesAsistidos')]
final class ListAssistedClientsRequest extends FormRequest
{
    /**
     * Validation rules for the search of assisted clients.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** Parte del nombre o del teléfono; sin ella trae los primeros por nombre. */
            'busqueda' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** The term the owner typed, if any. */
    public function term(): ?string
    {
        return $this->filled('busqueda') ? $this->string('busqueda')->toString() : null;
    }
}
