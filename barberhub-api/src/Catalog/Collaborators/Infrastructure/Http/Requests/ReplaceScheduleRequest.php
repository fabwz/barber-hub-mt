<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Infrastructure\Http\Requests;

use Closure;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Src\Catalog\Collaborators\Application\DTOs\ReplaceScheduleCommand;
use Src\Catalog\Collaborators\Application\DTOs\ScheduleEntry;
use Src\Shared\Domain\ValueObjects\DayOfWeek;

#[SchemaName('CambiarHorarioDeColaborador')]
final class ReplaceScheduleRequest extends FormRequest
{
    /**
     * Validation rules for the weekly shifts and breaks of a collaborator (RF-05, HO-02).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $day = ['required', 'integer', 'between:'.DayOfWeek::MONDAY.','.DayOfWeek::SUNDAY];

        return [
            /** Turnos de trabajo por día; una lista vacía deja al colaborador sin horario. Cada turno cae dentro del horario de la barbería. */
            'turnos' => ['present', 'array'],
            /** Día de la semana: 1 es lunes y 7 es domingo. */
            'turnos.*.dia' => $day,
            /** Hora de inicio, HH:MM en hora de Costa Rica; está incluida en el turno. */
            'turnos.*.inicia' => ['required', 'date_format:H:i'],
            /** Hora de fin, HH:MM; está excluida del turno y debe ser posterior a `inicia`. */
            'turnos.*.termina' => ['required', 'date_format:H:i'],
            /** Pausas por día; cada una cae dentro de un turno del mismo día. */
            'pausas' => ['present', 'array'],
            /** Día de la semana: 1 es lunes y 7 es domingo. */
            'pausas.*.dia' => $day,
            /** Hora de inicio de la pausa, HH:MM. */
            'pausas.*.inicia' => ['required', 'date_format:H:i'],
            /** Hora de fin de la pausa, HH:MM; debe ser posterior a `inicia`. */
            'pausas.*.termina' => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * An entry whose end is not after its start is refused naming the entry; a comparison rule cannot do it with a wildcard.
     *
     * @return list<Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (['turnos', 'pausas'] as $list) {
                    foreach ($this->array($list) as $index => $entry) {
                        if (! is_array($entry) || $validator->errors()->hasAny(["{$list}.{$index}.inicia", "{$list}.{$index}.termina"])) {
                            continue;
                        }

                        if (($entry['termina'] ?? '') <= ($entry['inicia'] ?? '')) {
                            $validator->errors()->add("{$list}.{$index}.termina", __('collaborators.end_not_after_start'));
                        }
                    }
                }
            },
        ];
    }

    /** Map the validated payload to the use case input. */
    public function toCommand(string $collaboratorId): ReplaceScheduleCommand
    {
        return new ReplaceScheduleCommand(
            $collaboratorId,
            $this->entries('turnos'),
            $this->entries('pausas'),
        );
    }

    /**
     * Turn a list of the payload into schedule entries.
     *
     * @return list<ScheduleEntry>
     */
    private function entries(string $list): array
    {
        return array_values(array_map(
            fn (array $entry): ScheduleEntry => new ScheduleEntry((int) $entry['dia'], (string) $entry['inicia'], (string) $entry['termina']),
            $this->array($list),
        ));
    }
}
