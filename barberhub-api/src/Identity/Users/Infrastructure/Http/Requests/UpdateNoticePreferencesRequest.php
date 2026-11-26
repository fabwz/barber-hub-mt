<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;

#[SchemaName('CambioDePreferenciasDeAvisos')]
final class UpdateNoticePreferencesRequest extends FormRequest
{
    /**
     * Validation rules: only the reminders, as a real boolean; nothing else is read from the body.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'recordatorios_correo' => ['required', 'boolean:strict'],
        ];
    }

    /** Whether the person wants the reminders. */
    public function remindersEnabled(): bool
    {
        return $this->boolean('recordatorios_correo');
    }
}
