<?php

declare(strict_types=1);

return [

    'address_invalid' => 'La dirección debe ir en una sola línea y no puede superar los :max caracteres.',
    'cancellation_deadline_invalid' => 'El plazo de cancelación gratuita debe estar entre :min y :max horas.',
    'city_invalid' => 'La ciudad es obligatoria y no puede superar los :max caracteres.',
    'contact_email_invalid' => 'El correo de contacto debe ser una dirección de correo válida y no puede superar los :max caracteres.',
    'deposit_percentage_invalid' => 'El porcentaje del depósito debe estar entre :min y :max.',
    'deposit_percentage_not_allowed' => 'El porcentaje del depósito solo se indica con la modalidad de depósito.',
    'deposit_percentage_required' => 'La modalidad de depósito exige un porcentaje.',
    'name_invalid' => 'El nombre de la barbería es obligatorio y no puede superar los :max caracteres.',
    'not_found' => 'Esta barbería no existe',
    'payment_gateway_required' => 'Cobrar un depósito o el pago total exige elegir una pasarela de pago.',
    'public_link_invalid' => 'El enlace solo admite minúsculas, dígitos y guiones, y no puede superar los :max caracteres.',
    'public_link_taken' => 'Ya existe una barbería con ese enlace.',
    'public_link_unavailable' => 'No se pudo asignar un enlace libre a la barbería. Intente con otro nombre.',
    'social_link_invalid' => 'El enlace debe empezar por https://, ser de :domains, no tener espacios y no superar los :max caracteres.',

];
