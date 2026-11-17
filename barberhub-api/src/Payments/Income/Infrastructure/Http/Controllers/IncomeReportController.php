<?php

declare(strict_types=1);

namespace Src\Payments\Income\Infrastructure\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Src\Payments\Income\Application\UseCases\GetIncomeReport;
use Src\Payments\Income\Infrastructure\Http\Requests\IncomeReportRequest;
use Src\Payments\Income\Infrastructure\Http\Resources\IncomeReportResource;

#[Group('Ingresos', 'Reporte de ingresos de la barbería para un rango de fechas, a cargo del dueño.')]
final readonly class IncomeReportController
{
    /** Show the income of the barbershop over a range of days. */
    #[Endpoint(
        title: 'Consultar los ingresos',
        description: 'Recibe `desde` y `hasta` (`AAAA-MM-DD`, ambos días incluidos, en la hora de Costa Rica; hasta 366 días) y devuelve tres cifras separadas, sin un total combinado (PA-11): `cobrado_en_linea` (`bruto`, `reembolsado` y `neto`: los pagos de reservas aprobados, por el día en que se crearon, menos los pagos reembolsados, por el día del reembolso; un pago pendiente de devolver sigue contando hasta que se reembolsa), `bonos_vendidos` (`total` y `cantidad` de los bonos activados en el rango, con el precio con que se vendieron) y `servicios_atendidos` (`total` y `cantidad` de las reservas completadas cuyo servicio empezó en el rango, por su precio completo aunque se pagara con bono). El saldo que se cobra en el local no figura (PA-10). Los montos son colones enteros. El `detalle` trae un renglón por cada día con movimiento, del más antiguo al más reciente. Un rango sin datos responde 200 con las cifras en cero y `detalle` vacío. Solo el dueño: un colaborador o un cliente reciben 403 `prohibido` y quien no tiene rol en la barbería, 404 `no-encontrado`. Un rango inválido (fechas que no existen, `hasta` anterior a `desde` o más de 366 días) responde 422 `datos-invalidos`.',
    )]
    public function show(IncomeReportRequest $request, GetIncomeReport $getIncomeReport): IncomeReportResource
    {
        return new IncomeReportResource($getIncomeReport->execute($request->from(), $request->to()));
    }
}
