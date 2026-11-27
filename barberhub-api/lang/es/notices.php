<?php

declare(strict_types=1);

/*
 * Texts of the emails to the customer (RF-33, RF-34), in Spanish of Costa Rica and addressing the person as «usted» (ADR 0008). The
 * code never writes a message: it picks a key and the parameters.
 */
return [
    'greeting' => 'Hola, :name.',
    'greeting_anonymous' => 'Hola.',
    'footer' => 'Este mensaje fue enviado por BarberHub en nombre de :barbershop.',

    'confirmation' => [
        'subject' => 'Su reserva en :barbershop está confirmada',
        'line' => 'Su reserva de :service con :collaborator está confirmada para el :when.',
    ],

    'offer' => [
        'subject' => 'Hay un espacio disponible para usted en :barbershop',
        'line' => 'Se liberó un espacio para :service con :collaborator el :when.',
        'deadline' => 'Puede aceptarlo hasta las :deadline. Si no responde a tiempo, el espacio se ofrecerá a la siguiente persona de la fila.',
        'action' => 'Ingrese a BarberHub para aceptarlo o rechazarlo.',
    ],

    'cancellation' => [
        'subject' => 'La barbería canceló su reserva en :barbershop',
        'line' => 'La barbería canceló su reserva de :service con :collaborator del :when.',
        'apology' => 'Lamentamos el inconveniente. Puede reservar otro espacio cuando lo desee.',
    ],

    'reschedule' => [
        'subject' => 'La barbería cambió la hora de su reserva en :barbershop',
        'line' => 'La barbería movió su reserva de :service con :collaborator.',
        'before' => 'Hora anterior: :previous.',
        'after' => 'Hora nueva: :when.',
    ],

    'password_reset' => [
        'subject' => 'Restablezca su contraseña de BarberHub',
        'line' => 'Recibimos una solicitud para restablecer la contraseña de su cuenta.',
        'validity' => 'El enlace es válido por :minutes minutos y solo se puede usar una vez.',
        'ignore' => 'Si usted no hizo la solicitud, ignore este mensaje: su contraseña no cambia.',
        'action_label' => 'Crear nueva contraseña',
        'footer' => 'Este mensaje fue enviado por BarberHub.',
    ],

    'collaborator_invitation' => [
        'subject' => ':barbershop le invita a unirse a su equipo en BarberHub',
        'line' => ':barbershop le invitó a unirse a su equipo en BarberHub. Cree su contraseña para activar su cuenta.',
        'validity' => 'El enlace es válido por :days días y solo se puede usar una vez.',
        'ignore' => 'Si usted no esperaba esta invitación, ignore este mensaje: no se crea ninguna cuenta.',
        'action_label' => 'Crear mi contraseña',
    ],

    'reminder' => [
        '24h' => [
            'subject' => 'Recordatorio: su reserva en :barbershop es mañana',
            'line' => 'Le recordamos que su reserva de :service con :collaborator es el :when.',
        ],
        '2h' => [
            'subject' => 'Recordatorio: su reserva en :barbershop es en 2 horas',
            'line' => 'Le recordamos que su reserva de :service con :collaborator es hoy, el :when.',
        ],
    ],
];
