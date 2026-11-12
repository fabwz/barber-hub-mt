<?php

declare(strict_types=1);

return [

    'assistant_unavailable' => 'El asistente de reservas no está disponible en este momento. Puede reservar con el formulario normal.',
    'author_system' => 'sistema',
    'cancellation_deadline_invalid' => 'El plazo de cancelación no puede ser negativo.',
    'clarification' => [
        'servicio' => '¿Qué servicio le gustaría reservar?',
        'fecha' => '¿Para qué día le gustaría reservar?',
        'hora' => '¿A qué hora le gustaría?',
    ],
    'customer_overlap' => 'Ya tiene una reserva confirmada que se solapa con ese horario en esta barbería.',
    'payment_window_expired' => 'El plazo para pagar esta reserva venció y su espacio fue liberado.',
    'price_invalid' => 'El precio de la reserva debe ser de al menos ₡1.',
    'reschedule_deadline_expired' => 'Ya pasó el plazo para reprogramar esta reserva sin penalización.',
    'reschedule_limit_reached' => 'La reserva ya se reprogramó el máximo de 2 veces.',
    'slot_unavailable' => 'El espacio ya no está disponible. Elija uno de los espacios alternativos.',
    'start_invalid' => 'El inicio debe escribirse como fecha y hora ISO 8601 con desfase, por ejemplo 2030-03-05T10:00:00-06:00.',
    'transition_not_allowed' => 'La reserva no puede pasar a ese estado.',

];
