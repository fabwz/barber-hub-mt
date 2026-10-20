<?php

declare(strict_types=1);

namespace Src\Catalog\Closures\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Catalog\Closures\Application\DTOs\RegisterClosureCommand;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureDate;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureReason;

#[SchemaName('RegistrarCierre')]
final class StoreClosureRequest extends FormRequest
{
    /**
     * Validation rules for a closure on a given date (HO-01).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** Fecha del cierre, AAAA-MM-DD; hoy o posterior, en hora de Costa Rica. */
            'fecha' => ['required', 'date_format:'.ClosureDate::FORMAT],
            'motivo' => ['nullable', 'string', 'max:'.ClosureReason::MAX_LENGTH],
        ];
    }

    /** Map the validated payload to the use case input. */
    public function toCommand(): RegisterClosureCommand
    {
        return new RegisterClosureCommand(
            $this->string('fecha')->toString(),
            $this->input('motivo'),
        );
    }
}
