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
| Solo el cliente dueño | 401 | pasa | 404 | 403 | 403 | 403 | 404 | 404 |
| Cliente de primer uso (RD-18, UP-09) | 401 | pasa | pasa | 403 | 403 | 403 | pasa | pasa |
| Mis reservas | 401 | pasa | pasa | 403 | 403 | 403 | lista vacía | lista vacía |
| Solo con sesión | 401 | pasa | pasa | pasa | pasa | pasa | pasa | pasa |

«Pasa» significa que la autorización deja pasar la petición; después puede fallar por el cuerpo o por el estado del recurso (422, 409).

## Rutas públicas a propósito

| Ruta | Motivo |
| --- | --- |
| `GET api/v1` | Índice de la API: nombre y versión, sin datos de nadie. |
| `POST api/v1/cuentas` | Registro de cliente (RF-01): todavía no hay sesión. |
| `POST api/v1/tokens` | Inicio de sesión: entrega el token; limita los intentos (SE-05). |
| `GET api/v1/enlaces/{enlace}` | Portada de la barbería por su enlace público (MT-07, RD-18): datos públicos (MT-09). |
| `GET api/v1/barberias/{barberia}/servicios` | Servicios activos de una barbería antes de tener sesión (RD-18). |
| `GET api/v1/barberias/{barberia}/disponibilidad` | Espacios libres antes de tener sesión (RD-18). |
| `POST api/v1/pagos/avisos/onvo` | Aviso de ONVO Pay (RF-22): sin sesión; el origen se verifica con la firma del proveedor. |
| `POST api/v1/pagos/avisos/paypal` | Aviso de PayPal (RF-22): sin sesión; el origen se verifica con la firma del proveedor. |
| `GET api/v1/pagos/retorno` | Retorno de la pasarela: no pertenece a una barbería y no cambia nada. |
| `GET salud` | Salud del servicio para el monitoreo: sin datos. |
| `GET docs/api` | Documentación de la API (Scramble): describe el contrato, sin datos. |
| `GET docs/api.json` | Contrato OpenAPI (Scramble): describe el contrato, sin datos. |
| `GET _scramble/dev-tools/devtools.js` | Recurso de la documentación (Scramble). |
| `GET storage/{path}` | Disco local del framework: solo sirve URL firmadas. |
| `PUT storage/{path}` | Disco local del framework: solo recibe URL firmadas. |

## Rutas que solo piden sesión

Los datos son de la propia persona.

| Ruta | Nota |
| --- | --- |
| `POST api/v1/barberias` | Registrar una barbería: quien la registra queda como su dueño (UP-03). |
| `GET api/v1/mis-barberias` | Barberías de la persona con su rol: nunca otras (UP-08, RF-43). |
| `DELETE api/v1/tokens` | Cierra todas las sesiones de la persona. |
| `DELETE api/v1/tokens/actual` | Cierra la sesión actual de la persona. |

## Operaciones de cliente que crean la primera membresía

Quien no tiene rol pasa y la operación le crea el de cliente; el dueño y el colaborador de esa barbería reciben 403 `prohibido` (RD-18, UP-09).

| Ruta | Nota |
| --- | --- |
| `POST api/v1/barberias/{barberia}/reservas` | Reservar crea el rol de cliente; dueño y colaborador reciben 403. |
| `POST api/v1/barberias/{barberia}/reservas/propuesta` | Propuesta de IA (RF-37): no crea nada; dueño y colaborador reciben 403. |
| `GET api/v1/barberias/{barberia}/mis-reservas` | Lectura de lo propio (CL-04): sin rol, lista vacía. |
| `POST api/v1/barberias/{barberia}/bonos/{bono}/compras` | Comprar un bono crea el rol de cliente en la misma transacción (RF-27, UP-09); dueño y colaborador reciben 403 `prohibido` y no se crea nada. Un bono que no existe, está retirado o es de otra barbería responde 404. |

## Rutas con rol (y propiedad del recurso)

| Ruta | Comprobación | Roles | Nota |
| --- | --- | --- | --- |
| `GET api/v1/barberias/{barberia}/reservas` | Rol y propiedad | dueño, colaborador | El dueño ve la agenda de todos; un colaborador solo la suya (CO-04). |
| `GET api/v1/barberias/{barberia}/reservas/{reserva}` | Rol y propiedad | dueño, colaborador, cliente | Cliente: solo las suyas; colaborador: solo las de su agenda; dueño: cualquiera de su barbería (UP-02, CO-04, CL-04). |
| `PATCH api/v1/barberias/{barberia}/reservas/{reserva}` | Rol y propiedad | dueño, cliente | El cliente reprograma la suya; el dueño, cualquiera de su barbería. |
| `POST api/v1/barberias/{barberia}/reservas/{reserva}/cancelacion` | Rol y propiedad | dueño, cliente | El cliente cancela la suya; el dueño, cualquiera de su barbería. |
| `POST api/v1/barberias/{barberia}/reservas/{reserva}/pagos` | Rol y propiedad | cliente | Solo el cliente paga su propia reserva. |
| `GET api/v1/barberias/{barberia}/pagos/{pago}` | Rol y propiedad | dueño, cliente | El cliente lee los pagos de sus reservas y de sus bonos; el dueño, cualquiera de su barbería; el colaborador no (CO-04). |
| `POST api/v1/barberias/{barberia}/pagos/{pago}/reembolsos` | Rol | dueño | Solo el dueño devuelve dinero (PA-08). El pago de un bono no se reembolsa: responde 409 `conflicto` (BO-07). |
| `GET api/v1/barberias/{barberia}/administracion/cobro` | Rol | dueño | Política de cobro: solo el dueño (UP-03). |
| `PATCH api/v1/barberias/{barberia}/administracion/cobro` | Rol | dueño | Política de cobro: solo el dueño (UP-03). |
| `GET api/v1/barberias/{barberia}/administracion/negocio` | Rol | dueño | Datos del negocio: solo el dueño (MT-09). |
| `PATCH api/v1/barberias/{barberia}/administracion/negocio` | Rol | dueño | Datos del negocio: solo el dueño (MT-09). |
| `GET api/v1/barberias/{barberia}/administracion/servicios` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `POST api/v1/barberias/{barberia}/administracion/servicios` | Rol | dueño | Administración de la barbería: solo su dueño. |
| `PATCH api/v1/barberias/{barberia}/administracion/servicios/{servicio}` | Rol | dueño | Administración de la barbería: solo su dueño. |
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
| `GET api/v1/barberias/{barberia}/administracion/bonos` | Rol | dueño | Bonos de la barbería: solo su dueño (UP-03, RF-26). |
| `POST api/v1/barberias/{barberia}/administracion/bonos` | Rol | dueño | Bonos de la barbería: solo su dueño (UP-03, RF-26). |
| `GET api/v1/barberias/{barberia}/administracion/bonos/{bono}` | Rol | dueño | Bonos de la barbería: solo su dueño (UP-03, RF-26). |
| `PATCH api/v1/barberias/{barberia}/administracion/bonos/{bono}` | Rol | dueño | Bonos de la barbería: solo su dueño (UP-03, RF-26). Editar, retirar o reactivar. |

## Cómo agregar una ruta

1. Escribir su regla en `AccessMatrix::rules()` con la clase que corresponda (pública, con sesión, de primer uso, con rol o con rol y propiedad) y, si no es pública, el perfil de respuestas de `AccessMatrix::OUTCOMES`.
2. Agregarla a este documento.
3. Si la ruta tiene un recurso propio, registrarlo además en `TenantResources` (`docs/architecture.md`, «Aislamiento entre barberías»).

La prueba guardiana falla mientras falte cualquiera de los pasos.
