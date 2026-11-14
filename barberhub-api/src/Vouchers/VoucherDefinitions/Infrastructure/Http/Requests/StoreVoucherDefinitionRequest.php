<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Vouchers\Shared\Domain\ValueObjects\SessionCount;
use Src\Vouchers\Shared\Domain\ValueObjects\ValidityDays;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherName;
use Src\Vouchers\VoucherDefinitions\Application\DTOs\CreateVoucherDefinitionCommand;

#[SchemaName('CrearBono')]
final class StoreVoucherDefinitionRequest extends FormRequest
{
    /** Largest price the integer column holds. */
    public const int MAX_PRICE = 2147483647;

    /**
     * Validation rules for a new voucher (BO-01, RF-26).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:'.VoucherName::MAX_LENGTH],
            /** Número de sesiones del bono: de 1 a 32767. */
            'sesiones' => ['required', 'integer', 'between:1,'.SessionCount::MAX],
            /** Colones enteros con el IVA de 13 % incluido, mayores que cero. */
            'precio' => ['required', 'integer', 'between:1,'.self::MAX_PRICE],
            /** Días de vigencia desde la activación: de 1 a 32767. */
            'vigencia_dias' => ['required', 'integer', 'between:1,'.ValidityDays::MAX],
            /** Identificadores de los servicios que cubre: al menos uno, de esta barbería y sin repetir. */
            'servicios' => ['required', 'array', 'min:1'],
            'servicios.*' => ['required', 'uuid', 'distinct:ignore_case'],
        ];
    }

    /** Map the validated payload to the use case input. */
    public function toCommand(): CreateVoucherDefinitionCommand
    {
        /** @var list<string> $services */
        $services = array_values(array_map(strval(...), $this->array('servicios')));

        return new CreateVoucherDefinitionCommand(
            $this->string('nombre')->toString(),
            $this->integer('sesiones'),
            $this->integer('precio'),
            $this->integer('vigencia_dias'),
            $services,
        );
    }
}
