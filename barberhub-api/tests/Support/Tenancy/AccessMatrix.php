<?php

declare(strict_types=1);

namespace Tests\Support\Tenancy;

/**
 * The permission matrix of the API (RF-42): every route with what it demands and what each kind of person gets. A route that
 * is not here fails the guard test, so a new route cannot reach the CI without its rule. docs/permissions.md shows the same list.
 */
final class AccessMatrix
{
    /** The people that try every route, in the order of the outcomes below. */
    public const array ACTORS = ['anonymous', 'customer', 'other_customer', 'collaborator', 'assigned_collaborator', 'owner', 'foreign_owner', 'foreign_collaborator'];

    /** A request that gets through the authorization (it may still fail later on its body or the state of the resource). */
    public const string OK = 'ok';

    /** A request that gets through and answers an empty list of data. */
    public const string EMPTY = 'empty';

    // Profiles of outcomes, one answer per actor in the order of ACTORS: a status, OK or EMPTY.
    public const string AUTHENTICATED = 'authenticated';

    public const string OWNER_ONLY = 'owner-only';

    public const string STAFF_AGENDA = 'staff-agenda';

    public const string CUSTOMER_OWN = 'customer-own';

    public const string OWNER_OR_CUSTOMER_OWN = 'owner-or-customer-own';

    public const string RESERVATION_DETAIL = 'reservation-detail';

    public const string FIRST_USE = 'first-use';

    public const string FIRST_USE_LIST = 'first-use-list';

    /**
     * What each actor gets in each profile. "customer" is the person that owns the resource (Ana), "other_customer" another
     * customer of the same barbershop, "collaborator" one that does not hold the reservation, "assigned_collaborator" the
     * one that does, and the two "foreign" ones belong to another barbershop and have no role in this one (UP-06).
     *
     * @var array<string, list<int|string>>
     */
    public const array OUTCOMES = [
        //                           anon  cust  other  collab assigned owner  f.own  f.col
        self::AUTHENTICATED => [401, self::OK, self::OK, self::OK, self::OK, self::OK, self::OK, self::OK],
        self::OWNER_ONLY => [401, 403, 403, 403, 403, self::OK, 404, 404],
        self::STAFF_AGENDA => [401, 403, 403, self::OK, self::OK, self::OK, 404, 404],
        self::CUSTOMER_OWN => [401, self::OK, 404, 403, 403, 403, 404, 404],
        self::OWNER_OR_CUSTOMER_OWN => [401, self::OK, 404, 403, 403, self::OK, 404, 404],
        self::RESERVATION_DETAIL => [401, self::OK, 404, 404, self::OK, self::OK, 404, 404],
        self::FIRST_USE => [401, self::OK, self::OK, 403, 403, 403, self::OK, self::OK],
        self::FIRST_USE_LIST => [401, self::OK, self::OK, 403, 403, 403, self::EMPTY, self::EMPTY],
    ];

    /** The three roles of a barbershop (UP-01). */
    private const array ALL_ROLES = ['owner', 'collaborator', 'customer'];

    private const string BASE = 'api/v1/barberias/{barberia}';

    /**
     * The rule of every route, keyed "METHOD uri" as the router writes it (HEAD left out).
     *
     * @return array<string, RouteRule>
     */
    public static function rules(): array
    {
        $admin = static fn (string $note = 'Administración de la barbería: solo su dueño.'): RouteRule => RouteRule::role(['owner'], self::OWNER_ONLY, $note);
        $base = self::BASE;

        return [
            // Public on purpose.
            'GET api/v1' => RouteRule::public('Índice de la API: nombre y versión, sin datos de nadie.'),
            'POST api/v1/cuentas' => RouteRule::public('Registro de cliente (RF-01): todavía no hay sesión.'),
            'POST api/v1/tokens' => RouteRule::public('Inicio de sesión: entrega el token; limita los intentos (SE-05).'),
            'GET api/v1/enlaces/{enlace}' => RouteRule::public('Portada de la barbería por su enlace público (MT-07, RD-18): datos públicos (MT-09).'),
            "GET {$base}/servicios" => RouteRule::public('Servicios activos de una barbería antes de tener sesión (RD-18).', scoped: true),
            "GET {$base}/disponibilidad" => RouteRule::public('Espacios libres antes de tener sesión (RD-18).', scoped: true),
            'POST api/v1/pagos/avisos/onvo' => RouteRule::public('Aviso de ONVO Pay (RF-22): sin sesión; el origen se verifica con la firma del proveedor.'),
            'POST api/v1/pagos/avisos/paypal' => RouteRule::public('Aviso de PayPal (RF-22): sin sesión; el origen se verifica con la firma del proveedor.'),
            'GET api/v1/pagos/retorno' => RouteRule::public('Retorno de la pasarela: no pertenece a una barbería y no cambia nada.'),
            'GET salud' => RouteRule::public('Salud del servicio para el monitoreo: sin datos.'),
            'GET docs/api' => RouteRule::public('Documentación de la API (Scramble): describe el contrato, sin datos.'),
            'GET docs/api.json' => RouteRule::public('Contrato OpenAPI (Scramble): describe el contrato, sin datos.'),
            'GET _scramble/dev-tools/devtools.js' => RouteRule::public('Recurso de la documentación (Scramble).', conditional: true),
            'GET storage/{path}' => RouteRule::public('Disco local del framework: solo sirve URL firmadas.', conditional: true),
            'PUT storage/{path}' => RouteRule::public('Disco local del framework: solo recibe URL firmadas.', conditional: true),

            // A session and nothing more: the data are the person's own.
            'POST api/v1/barberias' => RouteRule::authenticated('Registrar una barbería: quien la registra queda como su dueño (UP-03).'),
            'GET api/v1/mis-barberias' => RouteRule::authenticated('Barberías de la persona con su rol: nunca otras (UP-08, RF-43).'),
            'DELETE api/v1/tokens' => RouteRule::authenticated('Cierra todas las sesiones de la persona.'),
            'DELETE api/v1/tokens/actual' => RouteRule::authenticated('Cierra la sesión actual de la persona.'),

            // Customer operations that create the first membership (RD-18, UP-09).
            "POST {$base}/reservas" => RouteRule::firstUse(self::FIRST_USE, 'Reservar crea el rol de cliente; dueño y colaborador reciben 403.'),
            "POST {$base}/reservas/propuesta" => RouteRule::firstUse(self::FIRST_USE, 'Propuesta de IA (RF-37): no crea nada; dueño y colaborador reciben 403.'),
            "GET {$base}/mis-reservas" => RouteRule::firstUse(self::FIRST_USE_LIST, 'Lectura de lo propio (CL-04): sin rol, lista vacía.'),

            // Reservations.
            "GET {$base}/reservas" => RouteRule::owned(['owner', 'collaborator'], self::STAFF_AGENDA, 'El dueño ve la agenda de todos; un colaborador solo la suya (CO-04).'),
            "GET {$base}/reservas/{reserva}" => RouteRule::owned(self::ALL_ROLES, self::RESERVATION_DETAIL, 'Cliente: solo las suyas; colaborador: solo las de su agenda; dueño: cualquiera de su barbería (UP-02, CO-04, CL-04).'),
            "PATCH {$base}/reservas/{reserva}" => RouteRule::owned(['owner', 'customer'], self::OWNER_OR_CUSTOMER_OWN, 'El cliente reprograma la suya; el dueño, cualquiera de su barbería.'),
            "POST {$base}/reservas/{reserva}/cancelacion" => RouteRule::owned(['owner', 'customer'], self::OWNER_OR_CUSTOMER_OWN, 'El cliente cancela la suya; el dueño, cualquiera de su barbería.'),

            // Payments.
            "POST {$base}/reservas/{reserva}/pagos" => RouteRule::owned(['customer'], self::CUSTOMER_OWN, 'Solo el cliente paga su propia reserva.'),
            "GET {$base}/pagos/{pago}" => RouteRule::owned(['owner', 'customer'], self::OWNER_OR_CUSTOMER_OWN, 'El cliente lee los pagos de sus reservas; el dueño, cualquiera de su barbería; el colaborador no (CO-04).'),
            "POST {$base}/pagos/{pago}/reembolsos" => RouteRule::role(['owner'], self::OWNER_ONLY, 'Solo el dueño devuelve dinero (PA-08).'),

            // Administration of the barbershop: only its owner.
            "GET {$base}/administracion/cobro" => $admin('Política de cobro: solo el dueño (UP-03).'),
            "PATCH {$base}/administracion/cobro" => $admin('Política de cobro: solo el dueño (UP-03).'),
            "GET {$base}/administracion/negocio" => $admin('Datos del negocio: solo el dueño (MT-09).'),
            "PATCH {$base}/administracion/negocio" => $admin('Datos del negocio: solo el dueño (MT-09).'),
            "GET {$base}/administracion/servicios" => $admin(),
            "POST {$base}/administracion/servicios" => $admin(),
            "PATCH {$base}/administracion/servicios/{servicio}" => $admin(),
            "GET {$base}/administracion/horario" => $admin(),
            "POST {$base}/administracion/horario" => $admin(),
            "DELETE {$base}/administracion/horario/{franja}" => $admin(),
            "GET {$base}/administracion/cierres" => $admin(),
            "POST {$base}/administracion/cierres" => $admin(),
            "DELETE {$base}/administracion/cierres/{cierre}" => $admin(),
            "GET {$base}/administracion/colaboradores" => $admin(),
            "POST {$base}/administracion/colaboradores" => $admin(),
            "POST {$base}/administracion/colaboradores/mi-perfil" => $admin(),
            "GET {$base}/administracion/colaboradores/{colaborador}" => $admin(),
            "PATCH {$base}/administracion/colaboradores/{colaborador}" => $admin(),
            "PUT {$base}/administracion/colaboradores/{colaborador}/servicios" => $admin(),
            "PUT {$base}/administracion/colaboradores/{colaborador}/horario" => $admin(),
            "GET {$base}/administracion/bonos" => $admin('Bonos de la barbería: solo su dueño (UP-03, RF-26).'),
            "POST {$base}/administracion/bonos" => $admin('Bonos de la barbería: solo su dueño (UP-03, RF-26).'),
            "GET {$base}/administracion/bonos/{bono}" => $admin('Bonos de la barbería: solo su dueño (UP-03, RF-26).'),
            "PATCH {$base}/administracion/bonos/{bono}" => $admin('Bonos de la barbería: solo su dueño (UP-03, RF-26). Editar, retirar o reactivar.'),
        ];
    }

    /** The key a route has in the matrix: its methods without HEAD, then its URI. */
    public static function keyOf(string $methods, string $uri): string
    {
        return implode('|', array_diff(explode('|', $methods), ['HEAD'])).' '.$uri;
    }
}
