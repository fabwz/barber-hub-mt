<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;

#[SchemaName('ConsultarReservas')]
final class ListReservationsRequest extends ListMyReservationsRequest
{
    /**
     * Validation rules of the page and the filters, plus the collaborator.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            /** Identificador del colaborador por el que filtrar. El dueño elige a cualquiera de su barbería; un colaborador solo puede pedir el suyo. */
            'colaborador' => ['sometimes', 'uuid'],
        ];
    }

    /** The collaborator asked for, in the canonical lowercase of the identifiers. */
    protected function collaborator(): ?string
    {
        return $this->filled('colaborador') ? strtolower($this->string('colaborador')->toString()) : null;
    }
}
