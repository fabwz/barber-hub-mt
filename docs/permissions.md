# Matriz de permisos

Qué puede hacer cada persona en cada operación de la API (RF-42; reglas UP-01, UP-02, UP-03, UP-06, UP-07, UP-09, CO-04, CL-04). La fuente es `tests/Support/Tenancy/AccessMatrix.php`: una prueba guardiana recorre la lista real de rutas y falla si alguna no figura allí o si su middleware no es el que su regla declara. Este documento muestra la misma lista; otra prueba comprueba que no le falte ninguna ruta.

## Cómo se decide

1. Sin sesión, una ruta protegida responde **401**.
2. El rol es por barbería y se lee de la ruta (`{barberia}`), nunca del token, del cuerpo ni de un encabezado. El token de Sanctum identifica a la persona y no lleva permisos por rol, porque una persona puede ser dueña en una barbería y clienta en otra (UP-07).
3. Sin rol en la barbería de la ruta, o con un recurso de otra barbería o de otra persona, la respuesta es **404**, con el mismo cuerpo que un recurso inexistente (UP-06).
4. Con rol pero insuficiente para la operación, la respuesta es **403** `prohibido`.
5. Una persona tiene un único rol por barbería (UP-07): lo garantiza la llave única `(user_id, barbershop_id)` de `memberships`.
6. PostgreSQL es la segunda barrera: la seguridad a nivel de fila oculta las filas de otra barbería aunque una consulta olvide el filtro (MT-06).

## Quién recibe qué

La prueba `AccessMatrixTest` ejecuta cada ruta protegida como ocho personas: sin sesión; la clienta dueña del recurso; otra clienta de la misma barbería; un colaborador sin relación con el recurso; el colaborador al que pertenece; el dueño de la barbería; el dueño de otra barbería; y un colaborador de otra barbería.

| Perfil | Sin sesión | Clienta dueña | Otra clienta | Colaborador ajeno | Colaborador asignado | Dueño | Dueño de otra | Colaborador de otra |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Solo el dueño | 401 | 403 | 403 | 403 | 403 | pasa | 404 | 404 |
| Agenda del personal | 401 | 403 | 403 | pasa (la suya) | pasa (la suya) | pasa | 404 | 404 |
| Detalle de una reserva | 401 | pasa | 404 | 404 | pasa | pasa | 404 | 404 |
| Dueño o cliente dueño | 401 | pasa | 404 | 403 | 403 | pasa | 404 | 404 |
| Dueño o colaborador asignado | 401 | 403 | 403 | 404 | pasa | pasa | 404 | 404 |
| Solo el cliente dueño | 401 | pasa | 404 | 403 | 403 | 403 | 404 | 404 |
| Cliente de primer uso (RD-18, UP-09) | 401 | pasa | pasa | 403 | 403 | 403 | pasa | pasa |
| Mis reservas y mis bonos | 401 | pasa | pasa | 403 | 403 | 403 | lista vacía | lista vacía |
| Solo con sesión | 401 | pasa | pasa | pasa | pasa | pasa | pasa | pasa |

«Pasa» significa que la autorización deja pasar la petición; después puede fallar por el cuerpo o por el estado del recurso (422, 409).

## Rutas públicas a propósito

| Ruta | Motivo |
| --- | --- |
| `GET api/v1` | Índice de la API: nombre y versión, sin datos de nadie. |
| `POST api/v1/cuentas` | Registro de cliente (RF-01): todavía no hay sesión. |
| `POST api/v1/tokens` | Inicio de sesión: entrega el token; limita los intentos (SE-05). |
| `POST api/v1/contrasena/recuperacion` | Solicitud del enlace para restablecer la contraseña (RF-41): todavía no hay sesión; responde igual exista o no el correo y limita 5 solicitudes por hora por correo y por dirección (SE-06). |
| `POST api/v1/contrasena/restablecimiento` | Restablece la contraseña con el enlace del correo (RF-41): todavía no hay sesión; el token es de un solo uso y vence a los 60 minutos (SE-06). |
| `POST api/v1/invitaciones/consulta` | Dice qué barbería invita y si el correo ya tiene cuenta (RF-06): todavía no hay sesión; solo quien tiene el enlace del correo la usa y no lo gasta. La barbería sale del propio enlace, que la base de datos localiza con `locate_invitation_of_link`, nunca del cuerpo. Un enlace desconocido, vencido, usado o cancelado responde igual (UP-04). 10 por minuto por dirección. |
| `POST api/v1/invitaciones/aceptacion` | Acepta la invitación de un colaborador (RF-06): todavía no hay sesión; el token es de un solo uso y vence a los 7 días (UP-04). Crea la cuenta con la contraseña que la persona eligió (SE-07) o, si el correo ya tiene cuenta, le asigna el rol sin cambiar su contraseña; un correo con rol en esa barbería recibe 409 (UP-07). 10 por minuto por dirección. |
| `GET api/v1/enlaces/{enlace}` | Portada de la barbería por su enlace público (MT-07, RD-18): datos públicos (MT-09). |
| `GET api/v1/directorio` | Directorio de las barberías que eligieron aparecer (RF-54, MT-10): solo nombre, ciudad, logo y enlace (MT-09). |
| `GET api/v1/barberias/{barberia}/servicios` | Servicios activos de una barbería antes de tener sesión (RD-18). |
| `GET api/v1/barberias/{barberia}/disponibilidad` | Espacios libres antes de tener sesión (RD-18). |
| `GET api/v1/archivos/barbershops/{barberia}/{carpeta}/{archivo}` | Logo, banner e imágenes de servicios de una barbería (MT-05, MT-08, SV-07): son datos públicos; la carpeta sale de la barbería de la ruta, así que no sirve archivos de otra. |
| `POST api/v1/pagos/avisos/onvo` | Aviso de ONVO Pay (RF-22): sin sesión; el origen se verifica con la firma del proveedor. |
| `POST api/v1/pagos/avisos/paypal` | Aviso de PayPal (RF-22): sin sesión; el origen se verifica con la firma del proveedor. |
| `GET api/v1/pagos/retorno` | Retorno de la pasarela: no pertenece a una barbería y no cambia nada. |
| `GET salud` | Salud del servicio para el monitoreo: sin datos. |
| `GET docs/api` | Documentación de la API (Scramble): describe el contrato, sin datos. |
| `GET docs/api.json` | Contrato OpenAPI (Scramble): describe el contrato, sin datos. |
| `GET _scramble/dev-tools/devtools.js` | Recurso de la documentación (Scramble). |
| `GET storage/{path}` | Disco local del framework: solo sirve URL firmadas. |
| `PUT storage/{path}` | Disco local del framework: solo recibe URL firmadas. |

## Rutas de plataforma

Ni públicas ni de una barbería: no llevan `{barberia}`, no usan la sesión de Sanctum ni ningún rol, y pasan por su propio middleware de credenciales, que falla cerrado. Un rol de barbería (dueño, colaborador o cliente) no abre nada aquí. Cuando exista el operador de plataforma (#245), estas rutas pasarán a su comprobación. La regla es `RouteRule::platform()` en `AccessMatrix`, y la prueba guardiana exige que la ruta pase por `RequireMonitoringCredentials` y por nada de una barbería.

| Ruta | Comprobación | Nota |
| --- | --- | --- |
| `GET monitoreo` | Autenticación básica (`MONITOR_USER` y `MONITOR_PASSWORD_HASH`) | Panel de monitoreo de solo lectura (RNF-15, ADR 0013). Sin credenciales o con unas incorrectas, 401 con `WWW-Authenticate`; tras 5 intentos fallidos desde una misma dirección, 429 con `Retry-After` incluso con la contraseña correcta. Con `MONITOR_ENABLED=false` o sin el usuario o el hash configurados responde 404, como cualquier ruta que no existe: nunca queda abierta. Fuera de `/api/v1`, así que no está en el contrato OpenAPI. |

## Rutas que solo piden sesión

Los datos son de la propia persona.

| Ruta | Nota |
| --- | --- |
| `POST api/v1/barberias` | Registrar una barbería: quien la registra queda como su dueño (UP-03). |
| `GET api/v1/mis-barberias` | Barberías de la persona con su rol y, en cada una, el `colaborador` (id de su propio perfil de colaborador, o `null`): nunca otras barberías ni el perfil de otra persona (UP-08, RF-43, CO-04, #113). |
| `GET api/v1/cuenta` | Datos de la propia cuenta y si la contraseña es temporal (RF-45, UP-04); es de las pocas rutas que una contraseña temporal permite. |
| `PUT api/v1/cuenta` | Corrige nombre, teléfono y correo de la propia cuenta (RF-45, CL-03): nunca la de otra persona. |
| `PUT api/v1/cuenta/contrasena` | Cambia la contraseña de la propia cuenta (RF-40); es de las pocas rutas que una contraseña temporal permite (UP-04). |
| `GET api/v1/cuenta/avisos` | Preferencias de avisos de la propia cuenta: si recibe los recordatorios por correo (RF-47, NO-10); es de la persona, de cualquier rol o sin ninguno, y no lleva barbería. |
| `PUT api/v1/cuenta/avisos` | Activa o desactiva los recordatorios por correo de la propia cuenta (RF-47, NO-10): nunca los de otra persona; la confirmación y los demás avisos no se pueden desactivar. |
| `DELETE api/v1/tokens` | Cierra todas las sesiones de la persona. |
| `DELETE api/v1/tokens/actual` | Cierra la sesión actual de la persona. |
| `POST api/v1/tiempo-real/autorizacion` | Autoriza la suscripción a un canal privado de la agenda en vivo (RF-36). La ruta pide solo sesión; el rol sale del nombre del canal y lo decide el caso de uso: `private-barberia.{barberia}.agenda` es del dueño de esa barbería y `private-barberia.{barberia}.colaboradores.{colaborador}`, de ese colaborador. Sin rol, barbería inexistente, identificador inválido o agenda de otro colaborador, 404; cliente, colaborador en el canal de la barbería o dueño en el de un colaborador, 403 (CO-04, UP-06). `private-usuario.{usuario}` lleva los avisos de las ofertas de la lista de espera (RF-35, NO-08) y es solo de esa persona, sea cual sea su rol: el canal de otra persona, de una cuenta que no existe o con un identificador inválido responde el mismo 404. |

## Operaciones de cliente que crean la primera membresía

Quien no tiene rol pasa y la operación le crea el de cliente; el dueño y el colaborador de esa barbería reciben 403 `prohibido` (RD-18, UP-09).

| Ruta | Nota |
| --- | --- |
| `POST api/v1/barberias/{barberia}/reservas` | Reservar crea el rol de cliente; dueño y colaborador reciben 403. |
| `POST api/v1/barberias/{barberia}/reservas/propuesta` | Propuesta de IA (RF-37): no crea nada; dueño y colaborador reciben 403. |
| `GET api/v1/barberias/{barberia}/mis-reservas` | Lectura de lo propio (CL-04): sin rol, lista vacía. |
| `GET api/v1/barberias/{barberia}/mis-bonos` | Lectura de lo propio (RF-29, CL-04): sin rol, lista vacía; dueño y colaborador reciben 403 `prohibido`. Solo trae los bonos ya pagados de la persona. |
| `GET api/v1/barberias/{barberia}/bonos` | Bonos a la venta con sus servicios cubiertos (RF-27, UP-09): una persona sin rol pasa y no se le crea ninguna membresía; dueño y colaborador reciben 403 `prohibido`. Un bono retirado no aparece. |
| `POST api/v1/barberias/{barberia}/bonos/{bono}/compras` | Comprar un bono crea el rol de cliente en la misma transacción (RF-27, UP-09); dueño y colaborador reciben 403 `prohibido` y no se crea nada. Un bono que no existe, está retirado o es de otra barbería responde 404. |
| `POST api/v1/barberias/{barberia}/filas` | Anotarse en la lista de espera crea el rol de cliente en la misma transacción (RF-30, UP-09); dueño y colaborador reciben 403 `prohibido` y no se crea nada. Con espacios disponibles, con 3 filas activas ya, con una fila igual o con la lista desactivada responde 409 `conflicto`. Un servicio o un barbero que no existe o es de otra barbería responde 404. |
| `GET api/v1/barberias/{barberia}/mis-filas` | Lectura de lo propio (RF-30, CL-04): sin rol, lista vacía; dueño y colaborador reciben 403 `prohibido`. Solo trae las filas activas de la persona. |
| `DELETE api/v1/barberias/{barberia}/filas/{fila}` | Solo el cliente sale de su propia fila (RF-30, CL-04): la de otra persona, la que no existe, la de otra barbería y quien no tiene rol responden el mismo 404; dueño y colaborador reciben 403 `prohibido`. |
| `GET api/v1/barberias/{barberia}/mis-ofertas` | Lectura de lo propio (RF-32, CL-04): sin rol, lista vacía; dueño y colaborador reciben 403 `prohibido`. Solo trae las ofertas `pendiente` de la persona que siguen dentro de sus 15 minutos. |
| `POST api/v1/barberias/{barberia}/ofertas/{oferta}/aceptacion` | Solo el cliente acepta su propia oferta (RF-32, CL-04): la de otra persona, la que no existe, la de otra barbería y quien no tiene rol responden el mismo 404; dueño y colaborador reciben 403 `prohibido`. Una oferta pasada de sus 15 minutos responde 409 `oferta-vencida`. En la matriz, la oferta del escenario está vencida, así que su dueña recibe ese 409. |
| `POST api/v1/barberias/{barberia}/ofertas/{oferta}/rechazo` | Igual que la aceptación: solo el cliente rechaza su propia oferta (RF-32, CL-04); otra persona, otra barbería o sin rol, 404; dueño y colaborador, 403 `prohibido`. La oferta vencida del escenario da 409 `oferta-vencida`. |

## Rutas con rol (y propiedad del recurso)

| Ruta | Comprobación | Roles | Nota |
| --- | --- | --- | --- |
| `GET api/v1/barberias/{barberia}/reservas` | Rol y propiedad | dueño, colaborador | El dueño ve la agenda de todos; un colaborador solo la suya (CO-04). |
| `GET api/v1/barberias/{barberia}/reservas/{reserva}` | Rol y propiedad | dueño, colaborador, cliente | Cliente: solo las suyas; colaborador: solo las de su agenda; dueño: cualquiera de su barbería (UP-02, CO-04, CL-04). |
| `PATCH api/v1/barberias/{barberia}/reservas/{reserva}` | Rol y propiedad | dueño, cliente | El cliente reprograma la suya; el dueño, cualquiera de su barbería. |
| `POST api/v1/barberias/{barberia}/reservas/{reserva}/cancelacion` | Rol y propiedad | dueño, cliente | El cliente cancela la suya; el dueño, cualquiera de su barbería. La respuesta informa la consecuencia sobre el depósito y la sesión del bono (RF-17); no hay ruta nueva ni cambia quién puede qué. |
| `POST api/v1/barberias/{barberia}/reservas/{reserva}/cierre` | Rol y propiedad | dueño, colaborador | El colaborador marca atendida o ausente solo las de su agenda (la de otro colaborador, 404); el dueño, cualquiera de su barbería (RF-18, CO-05). El cliente recibe 403. Antes de la hora de inicio, o de 10 minutos después para «ausente», responde 409 `conflicto`. |
| `POST api/v1/barberias/{barberia}/reservas/{reserva}/pagos` | Rol y propiedad | cliente | Solo el cliente paga su propia reserva. |
| `GET api/v1/barberias/{barberia}/pagos/{pago}` | Rol y propiedad | dueño, cliente | El cliente lee los pagos de sus reservas y de sus bonos; el dueño, cualquiera de su barbería; el colaborador no (CO-04). |
| `POST api/v1/barberias/{barberia}/pagos/{pago}/reembolsos` | Rol | dueño | Solo el dueño devuelve dinero (PA-08). El pago de un bono no se reembolsa: responde 409 `conflicto` (BO-07). |
| `GET api/v1/barberias/{barberia}/administracion/cobro` | Rol | dueño | Política de cobro: solo el dueño (UP-03). |
| `PATCH api/v1/barberias/{barberia}/administracion/cobro` | Rol | dueño | Política de cobro: solo el dueño (UP-03). |
| `GET api/v1/barberias/{barberia}/administracion/negocio` | Rol | dueño | Datos del negocio: solo el dueño (MT-09). |
| `PATCH api/v1/barberias/{barberia}/administracion/negocio` | Rol | dueño | Datos del negocio: solo el dueño (MT-09). |
| `POST api/v1/barberias/{barberia}/administracion/imagenes/logo` | Rol | dueño | Logo de la barbería: solo el dueño lo sube o lo reemplaza (RF-46, MT-09). |
| `POST api/v1/barberias/{barberia}/administracion/imagenes/banner` | Rol | dueño | Banner de la barbería: solo el dueño lo sube o lo reemplaza (RF-46, MT-09). |
| `GET api/v1/barberias/{barberia}/administracion/funciones` | Rol | dueño | Activación de bonos, lista de espera y aparición en el directorio: solo el dueño (RF-09, RF-54, UP-03). |
| `PATCH api/v1/barberias/{barberia}/administracion/funciones` | Rol | dueño | Activación de bonos, lista de espera y aparición en el directorio: solo el dueño (RF-09, RF-54, UP-03). Con los bonos desactivados, comprar o definir un bono nuevo responde 409 `conflicto`. |
| `GET api/v1/barberias/{barberia}/administracion/ingresos` | Rol | dueño | Reporte de ingresos de un rango de fechas (`desde` y `hasta`, hasta 366 días): solo su dueño (RF-25, PA-11, CO-04, MT-02). Colaborador y cliente reciben 403 `prohibido`; quien no tiene rol en la barbería, 404. Un rango sin datos responde 200 con ceros. |
| `GET api/v1/barberias/{barberia}/administracion/servicios` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `POST api/v1/barberias/{barberia}/administracion/servicios` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `PATCH api/v1/barberias/{barberia}/administracion/servicios/{servicio}` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `POST api/v1/barberias/{barberia}/administracion/servicios/{servicio}/imagen` | Rol | dueño | Imagen de un servicio: solo el dueño la sube o la reemplaza (RF-46, SV-07). |
| `GET api/v1/barberias/{barberia}/administracion/horario` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `POST api/v1/barberias/{barberia}/administracion/horario` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `DELETE api/v1/barberias/{barberia}/administracion/horario/{franja}` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `GET api/v1/barberias/{barberia}/administracion/cierres` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `POST api/v1/barberias/{barberia}/administracion/cierres` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `DELETE api/v1/barberias/{barberia}/administracion/cierres/{cierre}` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `GET api/v1/barberias/{barberia}/administracion/colaboradores` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `POST api/v1/barberias/{barberia}/administracion/colaboradores` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `POST api/v1/barberias/{barberia}/administracion/colaboradores/mi-perfil` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `GET api/v1/barberias/{barberia}/administracion/colaboradores/{colaborador}` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `PATCH api/v1/barberias/{barberia}/administracion/colaboradores/{colaborador}` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `PUT api/v1/barberias/{barberia}/administracion/colaboradores/{colaborador}/servicios` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `PUT api/v1/barberias/{barberia}/administracion/colaboradores/{colaborador}/horario` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `GET api/v1/barberias/{barberia}/administracion/invitaciones` | Rol | dueño | Invitaciones de colaboradores: solo el dueño las ve, las envía y las cancela (RF-06, UP-03, UP-04). |
| `POST api/v1/barberias/{barberia}/administracion/invitaciones` | Rol | dueño | Invitaciones de colaboradores: solo el dueño las ve, las envía y las cancela (RF-06, UP-03, UP-04). |
| `DELETE api/v1/barberias/{barberia}/administracion/invitaciones/{invitacion}` | Rol | dueño | Invitaciones de colaboradores: solo el dueño las ve, las envía y las cancela (RF-06, UP-03, UP-04). |
| `GET api/v1/barberias/{barberia}/administracion/bonos` | Rol | dueño | Bonos de la barbería: solo su dueño (UP-03, RF-26). |
| `POST api/v1/barberias/{barberia}/administracion/bonos` | Rol | dueño | Bonos de la barbería: solo su dueño (UP-03, RF-26). |
| `GET api/v1/barberias/{barberia}/administracion/bonos/{bono}` | Rol | dueño | Bonos de la barbería: solo su dueño (UP-03, RF-26). |
| `GET api/v1/barberias/{barberia}/administracion/bonos-vendidos` | Rol | dueño | Bonos vendidos en la barbería, con el nombre del cliente, saldo y vigencia: solo su dueño (RF-29, MT-02). Colaborador y cliente reciben 403. |
| `PATCH api/v1/barberias/{barberia}/administracion/bonos/{bono}` | Rol | dueño | Bonos de la barbería: solo su dueño (UP-03, RF-26). Editar, retirar o reactivar. |

## Cómo agregar una ruta

1. Escribir su regla en `AccessMatrix::rules()` con la clase que corresponda (pública, de plataforma, con sesión, de primer uso, con rol o con rol y propiedad) y, si no es pública, el perfil de respuestas de `AccessMatrix::OUTCOMES`.
2. Agregarla a este documento.
3. Si la ruta tiene un recurso propio, registrarlo además en `TenantResources` (`docs/architecture.md`, «Aislamiento entre barberías»).

La prueba guardiana falla mientras falte cualquiera de los pasos.
