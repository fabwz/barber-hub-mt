<?php

declare(strict_types=1);

return [

    'active_payment_exists' => 'La reserva ya tiene un pago en curso o aprobado.',
    'amount_invalid' => 'El monto del pago debe ser de al menos ₡1.',
    'charge_not_required' => 'La barbería no cobra por adelantado: no hay nada que pagar.',
    'gateway_unavailable' => 'La pasarela de pago no responde en este momento. Intente de nuevo en unos minutos.',
    'request_rejected' => 'La pasarela no pudo procesar los datos del pago. Revise los datos e intente de nuevo.',
    'returned' => 'Regresaste del proveedor de pago: consulta el estado de tu pago.',
    'refund_not_supported' => 'La pasarela con que se cobró este pago no permite reembolsos desde el sistema.',
    'refund_refused' => 'La pasarela no aceptó el reembolso. El pago sigue aprobado.',
    'reservation_not_open' => 'La reserva ya no espera su pago: venció su plazo o dejó de ser temporal.',
    'exchange_rate_invalid' => 'El tipo de cambio debe ser un número positivo.',
    'currency_invalid' => 'La moneda del proveedor debe ser un código de tres letras mayúsculas.',
    'method_not_supported' => 'La pasarela de la barbería no admite el medio de pago :method.',
    'provider_amount_invalid' => 'El monto del proveedor debe ser de al menos una unidad mínima.',
    'provider_payment_id_invalid' => 'El identificador del pago en el proveedor no puede estar vacío.',
    'transition_not_allowed' => 'El pago no puede pasar a ese estado.',

];
