<?php

declare(strict_types=1);

return [

    'invalid_identifier' => 'El identificador no es válido.',
    'file_not_found' => 'El archivo no existe.',
    'money_negative' => 'El monto no puede ser negativo.',
    'money_below_zero' => 'El monto resultante no puede ser menor que cero.',
    'duration_not_positive' => 'La duración debe ser de al menos un minuto.',
    'percentage_out_of_range' => 'El porcentaje debe estar entre 0 y 100.',
    'phone_invalid' => 'El teléfono es obligatorio y no puede superar los :max caracteres.',
    'interval_start_not_before_end' => 'El inicio del intervalo debe ser anterior a su fin.',
    'day_of_week_out_of_range' => 'El día de la semana debe estar entre :min (lunes) y :max (domingo).',
    'time_of_day_invalid' => 'La hora debe escribirse como HH:MM, de 00:00 a 23:59.',
    'time_range_start_not_before_end' => 'La hora de apertura debe ser anterior a la de cierre.',
    'report_period_invalid_date' => 'Las fechas del rango deben escribirse como AAAA-MM-DD y existir.',
    'report_period_reversed' => 'La fecha inicial no puede ser posterior a la final.',
    'report_period_too_long' => 'El rango no puede superar los :max días.',
    'page_out_of_range' => 'La página empieza en 1 y tiene de 1 a :max elementos.',

];
