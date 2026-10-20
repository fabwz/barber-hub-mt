<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Src\Catalog\OpeningHours\Application\DTOs\AddTimeSlotCommand;
use Src\Shared\Domain\ValueObjects\DayOfWeek;

#[SchemaName('CrearFranja')]
final class StoreTimeSlotRequest extends FormRequest
{
    /**
     * Validation rules for a new slot of the weekly schedule (HO-01).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** Día de la semana: 1 es lunes y 7 es domingo. */
            'dia' => ['required', 'integer', 'between:'.DayOfWeek::MONDAY.','.DayOfWeek::SUNDAY],
            /** Hora de apertura, HH:MM en hora de Costa Rica; está incluida en la franja. */
            'abre' => ['required', 'date_format:H:i'],
            /** Hora de cierre, HH:MM; está excluida de la franja y debe ser posterior a `abre`. */
            'cierra' => ['required', 'date_format:H:i', 'after:abre'],
        ];
    }

    /** Map the validated payload to the use case input. */
    public function toCommand(): AddTimeSlotCommand
    {
        return new AddTimeSlotCommand(
            $this->integer('dia'),
            $this->string('abre')->toString(),
            $this->string('cierra')->toString(),
        );
    }
}
