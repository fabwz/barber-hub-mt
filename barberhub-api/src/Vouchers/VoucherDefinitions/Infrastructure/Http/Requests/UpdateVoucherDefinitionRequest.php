<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Vouchers\Shared\Domain\ValueObjects\SessionCount;
use Src\Vouchers\Shared\Domain\ValueObjects\ValidityDays;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherName;
use Src\Vouchers\VoucherDefinitions\Application\DTOs\UpdateVoucherDefinitionCommand;

#[SchemaName('ActualizarBono')]
final class UpdateVoucherDefinitionRequest extends FormRequest
{
    /**
     * Validation rules for a partial update of a voucher (BO-01, RF-26).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:'.VoucherName::MAX_LENGTH],
            /** De 1 a 32767. Un bono ya vendido no cambia de sesiones. */
            'sesiones' => ['sometimes', 'required', 'integer', 'between:1,'.SessionCount::MAX],
            /** Colones enteros con el IVA de 13 % incluido, mayores que cero. */
            'precio' => ['sometimes', 'required', 'integer', 'between:1,'.StoreVoucherDefinitionRequest::MAX_PRICE],
            /** De 1 a 32767. Un bono ya vendido no cambia de vigencia. */
            'vigencia_dias' => ['sometimes', 'required', 'integer', 'between:1,'.ValidityDays::MAX],
            /** Lista completa de los servicios que cubre: al menos uno, de esta barbería y sin repetir. Un bono ya vendido no cambia de servicios. */
            'servicios' => ['sometimes', 'required', 'array', 'min:1'],
            'servicios.*' => ['required', 'uuid', 'distinct:ignore_case'],
            /** `false` retira el bono de la venta y `true` lo vuelve a poner. */
            'activo' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /** Map the validated payload to the use case input; a field that did not come keeps its current value. */
    public function toCommand(): UpdateVoucherDefinitionCommand
    {
        $data = $this->validated();

        return new UpdateVoucherDefinitionCommand(
            (string) $this->route('bono'),
            isset($data['nombre']) ? (string) $data['nombre'] : null,
            isset($data['sesiones']) ? (int) $data['sesiones'] : null,
            isset($data['precio']) ? (int) $data['precio'] : null,
            isset($data['vigencia_dias']) ? (int) $data['vigencia_dias'] : null,
            isset($data['servicios']) ? array_values(array_map(strval(...), (array) $data['servicios'])) : null,
            isset($data['activo']) ? (bool) $data['activo'] : null,
        );
    }
}
