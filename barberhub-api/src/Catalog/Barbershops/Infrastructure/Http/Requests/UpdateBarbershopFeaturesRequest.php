<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Catalog\Barbershops\Application\DTOs\UpdateBarbershopFeaturesCommand;

#[SchemaName('ActualizarFunciones')]
final class UpdateBarbershopFeaturesRequest extends FormRequest
{
    /**
     * Validation rules for a partial change of the switches (RF-09, RF-54).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            /** Activa o desactiva la venta de bonos nuevos. */
            'bonos_activos' => ['sometimes', 'required', 'boolean'],
            /** Activa o desactiva la lista de espera. */
            'lista_espera_activa' => ['sometimes', 'required', 'boolean'],
            /** Hace que la barbería aparezca o no en el directorio público (RF-54). Por defecto no aparece. */
            'aparece_en_directorio' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /** Map the validated payload to the use case input; a field that did not come keeps its current value. */
    public function toCommand(): UpdateBarbershopFeaturesCommand
    {
        $data = $this->validated();

        return new UpdateBarbershopFeaturesCommand(
            isset($data['bonos_activos']) ? (bool) $data['bonos_activos'] : null,
            isset($data['lista_espera_activa']) ? (bool) $data['lista_espera_activa'] : null,
            isset($data['aparece_en_directorio']) ? (bool) $data['aparece_en_directorio'] : null,
        );
    }
}
