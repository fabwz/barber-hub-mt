# ADR 0012: La agenda en vivo usa Laravel Reverb, canales privados y una cola propia

Estado: aceptada.
Fecha: 24 de noviembre de 2026.

## Contexto

RF-36 y NO-06 piden que la agenda del dueño y del colaborador cambie en menos de 5 s sin recargar, y RF-35 y NO-08 piden lo mismo para el aviso de una oferta. RD-12 y PD-03 dejaban la tecnología de tiempo real pendiente. Las reglas de acceso no cambian: un colaborador ve solo su agenda (CO-04), nadie de otra barbería recibe nada (MT-02) y un rol insuficiente se rechaza como en el resto de la API (UP-02, UP-06). La referencia del docente no tiene tiempo real.

## Decisión

**Servidor.** Laravel Reverb, un servidor WebSocket con el protocolo de Pusher, que corre aparte del servidor web (`php artisan reverb:start`). Un solo nodo, sin Redis. La API publica en él por HTTP y los navegadores se conectan por WebSocket.

**Canales.** Privados y con el nombre en español: `private-barberia.{barberia}.agenda`, que lleva todos los cambios de la agenda de una barbería y solo escucha su dueño, y `private-barberia.{barberia}.colaboradores.{colaborador}`, que lleva los de un colaborador y solo escucha ese colaborador. El dueño no entra a los canales de colaborador: ya oye todo en el de la barbería y filtra en el cliente.

**Autorización.** `POST /api/v1/tiempo-real/autorizacion` (`auth:sanctum`, `throttle:live-authorizations`, 60 por minuto y por persona). Recibe `socket_id` y `channel_name`, los nombres del protocolo del cliente, y firma la suscripción si la persona puede escuchar el canal. La regla vive en dos casos de uso de `Notifications/LiveUpdates` (`AuthorizeAgendaChannel` y `AuthorizeCollaboratorChannel`) que leen el rol por el puerto `BarbershopRoles`, el mismo que usa el middleware de barbería. Las respuestas son las de la API: 401 sin token, 403 `contrasena-temporal` con contraseña temporal, 404 `no-encontrado` sin rol, con una barbería inexistente, con un identificador inválido o con la agenda de otro colaborador, y 403 `prohibido` con un rol insuficiente o un canal que no existe. Los casos de uso lanzan excepciones de dominio, no devuelven `false`: el framework convertiría todo `false` en 403.

**Evento.** Uno solo, `.reserva.actualizada`, con `{reserva, colaborador, estado, inicio, fin, cambio, colaborador_anterior?, inicio_anterior?, ocurrio_en}`. `cambio` es `creada`, `confirmada`, `reprogramada`, `cancelada`, `cerrada` o `expirada`. No lleva datos personales ni de pago: el cliente pide el detalle a la API, donde los permisos deciden qué ve. Una reprogramación llega también a la agenda del colaborador que perdió la reserva.

**Emisión.** Los eventos de dominio de la reserva (`ReservationCreated`, `ReservationConfirmed`, `ReservationRescheduled`, `ReservationCancelled`, `ReservationClosed` y `ReservationExpired`) los oye un listener síncrono que entrega a `AgendaChanged`, un `ShouldBroadcast` con `ShouldDispatchAfterCommit`. El despachador lo retiene hasta que la transacción confirma, así que nadie oye algo que se deshizo, y luego deja en la cola una tarea que lo publica. Las clases de broadcast viven en Infrastructure, porque Domain no puede usar el framework.

**Cola propia.** `AgendaChanged` usa la cola `realtime` del mismo controlador `database`, servida por su propio worker: `php artisan queue:work --queue=realtime --sleep=1`. Con la espera por defecto de 3 s el worker gastaría más de la mitad de los 5 s, y en la cola `default` los correos, que se limitan por segundo, retrasarían el evento. La tarea reintenta 3 veces con 1 s de espera. No toca la base de datos, así que no necesita fijar una barbería.

**Pruebas.** `BROADCAST_CONNECTION=null` en `phpunit.xml`: ninguna prueba abre un socket. La emisión se prueba con `Queue::fake` y la autorización con la conexión `reverb` y claves falsas, que firma sin red; la conexión `null` no autoriza nada.

## Alternativas descartadas

- **`ShouldBroadcastNow`.** Haría la llamada HTTP a Reverb dentro de la petición, ya confirmada: un Reverb caído devolvería un error al usuario por algo que sí se guardó.
- **Compartir la cola `default`.** Una ráfaga de correos retrasaría la agenda más de 5 s.
- **Un solo canal por barbería con filtro en el cliente.** Un colaborador recibiría la agenda de los demás en su navegador, aunque no la mostrara: rompe CO-04.
- **Pusher, Ably o Mercure.** Servicios externos o piezas nuevas sin ventaja para un solo nodo.
- **`install:broadcasting` tal cual.** Registra la ruta de autorización con el middleware `web` y fuera de `/api/v1`, sin la prueba guardiana ni el CORS de la API.

## Consecuencias

- **La autorización se evalúa solo al suscribirse.** Si desactivan a un colaborador o le revocan el token, su conexión abierta sigue recibiendo hasta que se reconecte, y el cliente pide la autorización de nuevo en cada reconexión. Es un límite conocido y aceptado: los eventos no llevan datos personales.
- **Reverb no repite los eventos que se perdieron.** Al reconectar, el cliente vuelve a pedir el día que muestra con `GET /barberias/{barberia}/reservas` y descarta los eventos anteriores a esa lectura por su `ocurrio_en`. Un evento perdido sin desconexión (la tarea agotó sus 3 intentos y quedó en `failed_jobs`) deja la pantalla vieja hasta la próxima lectura.
- **El despliegue gana dos procesos:** el servidor Reverb y el worker `realtime`, además del worker de siempre, ahora con `--queue=default`.
- **Una ruta nueva** en la API y en el contrato OpenAPI, clasificada en la matriz de permisos.
- **Lo reutiliza el aviso de ofertas (#98):** servidor, ruta, cola, módulo y forma de probar. Solo agrega su canal por persona y sus eventos.
