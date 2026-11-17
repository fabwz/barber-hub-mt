<?php

declare(strict_types=1);

namespace Src\Payments\Income\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\ReportPeriod;

#[SchemaName('ConsultarIngresos')]
final class IncomeReportRequest extends FormRequest
{
    /**
     * Validation rules for the range of the report (RF-25): two real days, the first not after the last.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            /** Primer día del rango, incluido, como `AAAA-MM-DD` en la hora de Costa Rica. */
            'desde' => ['required', 'date_format:Y-m-d'],
            /** Último día del rango, incluido, como `AAAA-MM-DD`. No puede ser anterior a `desde` ni dejar un rango de más de 366 días. */
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ];
    }

    /**
     * Once both days are fine, the range must not be longer than a year, which the period decides.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                ReportPeriod::between($this->from(), $this->to());
            } catch (InvalidValueException $exception) {
                $validator->errors()->add('hasta', (string) __($exception->translationKey(), $exception->parameters()));
            }
        }];
    }

    /** First day of the range. */
    public function from(): string
    {
        return $this->string('desde')->toString();
    }

    /** Last day of the range. */
    public function to(): string
    {
        return $this->string('hasta')->toString();
    }
}
