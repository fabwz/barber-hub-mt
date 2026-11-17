<?php

declare(strict_types=1);

namespace Src\Payments\Income\Infrastructure\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Src\Payments\Income\Application\DTOs\IncomeDay;
use Src\Payments\Income\Application\DTOs\IncomeReport;

/**
 * @property-read IncomeReport $resource
 */
#[SchemaName('ReporteIngresos')]
final class IncomeReportResource extends JsonResource
{
    /** Wrap the income report of a range. */
    public function __construct(private readonly IncomeReport $report)
    {
        parent::__construct($report);
    }

    /**
     * Expose the range, the three figures apart and the detail per day (colones).
     *
     * @return array{desde: string, hasta: string, cobrado_en_linea: array{bruto: int, reembolsado: int, neto: int}, bonos_vendidos: array{total: int, cantidad: int}, servicios_atendidos: array{total: int, cantidad: int}, detalle: list<array{fecha: string, cobrado: int, reembolsado: int, neto: int, bonos_vendidos: int, servicios_atendidos: int}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'desde' => $this->report->from,
            'hasta' => $this->report->to,
            'cobrado_en_linea' => [
                'bruto' => $this->report->charged,
                'reembolsado' => $this->report->refunded,
                'neto' => $this->report->net,
            ],
            'bonos_vendidos' => ['total' => $this->report->vouchersTotal, 'cantidad' => $this->report->vouchersCount],
            'servicios_atendidos' => ['total' => $this->report->servicesTotal, 'cantidad' => $this->report->servicesCount],
            'detalle' => array_map(fn (IncomeDay $day): array => [
                'fecha' => $day->date,
                'cobrado' => $day->charged,
                'reembolsado' => $day->refunded,
                'neto' => $day->net,
                'bonos_vendidos' => $day->vouchersSold,
                'servicios_atendidos' => $day->servicesAttended,
            ], $this->report->days),
        ];
    }
}
