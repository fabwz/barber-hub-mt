# Arquitectura de barberhub-api

`barberhub-api` combina arquitectura hexagonal (puertos y adaptadores) con diseño guiado por el dominio. El objetivo es que las reglas del negocio se puedan leer, probar y cambiar sin depender de Laravel.

> **Estado:** documento inicial (issue #21). El mapa de contextos se incorpora en los issues #21 y #23. El mapa de contextos está en context-map.md.

## Estructura

Todo el código del producto vive en `barberhub-api/src/`, bajo el namespace `Src\`. La forma es siempre contexto, módulo y capa:

```
src/
├── Shared/                      Núcleo compartido entre contextos
│   ├── Domain/
│   ├── Application/
│   └── Infrastructure/
└── <Contexto>/                  Un contexto del dominio (ver abajo)
    ├── Shared/                  Lo común a los módulos del contexto
    └── <Módulo>/                Un agregado; por ejemplo, Services
        ├── Domain/
        │   ├── Entities/
        │   ├── ValueObjects/
        │   ├── Contracts/       Puertos (interfaces)
        │   └── Exceptions/
        ├── Application/
        │   ├── UseCases/
        │   └── DTOs/
        └── Infrastructure/
            ├── Http/            Controllers, Requests, Resources y routes.php
            └── Persistence/     Models, Repositories y Queries
```

Hoy existen cuatro contextos, cada uno con sus módulos y las tres capas (las subcarpetas aparecen con el primer archivo que las necesita):

| Contexto | Módulos |
| --- | --- |
| Identity | `Users`, `Memberships` |
| Catalog | `Barbershops`, `Services`, `OpeningHours`, `Closures`, `Collaborators` |
| Availability | `Slots` |
| Reservations | `Reservations` |

`Payments`, `Vouchers`, `Waitlist` y `Notifications` están previstos (RD-05) y se crean en el milestone que los necesita. `Src\Shared` ya existe; los `Shared` de cada contexto (previsto, #22) aún no existen.

Un módulo corresponde a un agregado, no a una tabla.

Las carpetas `app/`, `bootstrap/`, `config/`, `database/`, `lang/` y `routes/` conservan su función en Laravel. `app/` solo contiene el arranque del framework. Las migraciones, las fábricas y los sembradores se quedan en `database/`.

## Regla de dependencias

Las dependencias apuntan siempre hacia adentro:

```
Infrastructure  ──▶  Application  ──▶  Domain
```

| Capa | Puede depender de | No puede depender de |
| --- | --- | --- |
| Domain | PHP, su propio módulo, el `Shared` de su contexto y `Src\Shared\Domain` | Application, Infrastructure, Laravel |
| Application | Domain | Infrastructure, Laravel |
| Infrastructure | Application, Domain, Laravel | — |

La prueba `tests/Architecture/LayerDependencyTest.php` revisa cada archivo de `Domain` y `Application` y falla si alguno rompe la regla.

## Qué va en cada capa

| Capa | Contenido |
| --- | --- |
| Domain | Entidades y agregados, objetos de valor, excepciones del dominio e interfaces de los puertos (por ejemplo, un repositorio). |
| Application | Un caso de uso por clase, con un único método público, `execute`. Recibe los puertos por constructor y no conoce HTTP ni Eloquent. Los DTO llevan datos primitivos. |
| Infrastructure | Controladores, Form Requests, API Resources, rutas, modelos Eloquent, implementaciones de los puertos y el service provider del módulo. |

Convenciones de nombres: las interfaces, los objetos de valor, los DTO y los casos de uso no llevan sufijo de tipo (`ReservationRepository`, `Money`, `CreateReservation`). Las excepciones terminan en `Exception` y los modelos Eloquent en `Model`. Un agregado se crea con `create()` y se rearma desde la base con `reconstitute()`; su constructor es privado.

## Flujo de una petición

1. La ruta del módulo (`Infrastructure/Http/routes.php`) entrega la petición a un controlador.
2. En las rutas con `{barberia}`, el middleware `barbershop` verifica que la persona autenticada tenga un rol en esa barbería, abre una transacción y fija en PostgreSQL la barbería de la petición.
3. Un Form Request valida la forma de los datos antes de llegar al controlador.
4. El controlador invoca un caso de uso con un DTO de datos primitivos.
5. El caso de uso trabaja con el dominio y con los puertos.
6. El adaptador de persistencia traduce entre el dominio y Eloquent.
7. Un API Resource construye la respuesta y expone solo los campos permitidos.

## Puertos y adaptadores

Un puerto es una interfaz definida en `Domain/Contracts`. Su adaptador vive en `Infrastructure` y se enlaza en el service provider del módulo, que se registra en `bootstrap/providers.php`. Cambiar de proveedor (una pasarela de pago, el servicio de IA, el correo) afecta a un solo adaptador.

El dominio de un módulo solo puede depender de su propio módulo, del `Shared` de su contexto y de `Src\Shared`. Cuando dos contextos necesitan colaborar lo hacen por un puerto o por un evento de dominio, nunca importando el dominio del otro.

### Colaboración entre contextos

Un ejemplo previsto: `Reservations` necesita saber si un espacio está disponible, pero la regla pertenece al contexto `Availability`. `Reservations` no importa ese dominio: declara el puerto `SlotAvailability` en su propia capa `Application/Contracts` y lo implementa con un adaptador de infraestructura que invoca el caso de uso de `Availability/Slots`.

```
CreateReservation ──▶ SlotAvailability (puerto de Reservations)
                              ▲
        AvailabilitySlotAvailability (adaptador) ──▶ caso de uso de Availability/Slots
```

Cuando un hecho debe provocar efectos en otros contextos, el emisor publica un evento de dominio y cada receptor lo atiende en un listener de su propia infraestructura:

| Evento | Quién reacciona | Qué hace |
| --- | --- | --- |
| `PaymentConfirmed` | `Reservations` y `Vouchers` | Confirma la reserva temporal o activa el bono comprado |
| `ReservationConfirmed`, `ReservationCancelled`, `ReservationRescheduled` | `Notifications` | Envía el correo y actualiza la agenda en vivo |
| `SpaceReleased` | `Waitlist` | Ofrece el espacio a la primera persona compatible |
| `OfferCreated` | `Notifications` | Envía el correo y el aviso emergente |

### Colaboración entre módulos de un contexto

Los módulos de un mismo contexto tampoco se importan el dominio. `OpeningHours` necesita saber si una fecha tiene un cierre, que es dato de `Closures` (#47): declara el puerto `ClosedDates` en su `Application/Contracts` y lo implementa `ClosuresClosedDates`, un adaptador de su infraestructura que invoca el caso de uso `IsClosedOn` de `Closures`. Con él, el caso de uso `ListOpenRangesOnDate` devuelve las franjas abiertas de una fecha (ninguna si hay un cierre); es lo que leerá la disponibilidad (#53).

Los objetos de valor `DayOfWeek`, `TimeOfDay` y `TimeRange` viven en `Src\Shared\Domain\ValueObjects` y no en `OpeningHours`, porque el horario de los colaboradores (#48) y la disponibilidad (#53) los usan y el dominio de un módulo no puede importar el de otro. Es una divergencia con la referencia (SodaYa), donde viven en su módulo de horario.

### Colaboradores (#48)

`Collaborators` es la composición más grande hasta ahora y habla con cuatro módulos por puertos de su `Application/Contracts`, cada uno con su adaptador en su infraestructura:

| Puerto | Adaptador | Módulo que responde |
| --- | --- | --- |
| `StaffAccounts` | `IdentityStaffAccounts` | `Identity/Users`: `RegisterStaffAccount` crea la cuenta con una contraseña temporal que cumple SE-07 y `ListAccountSummaries` da el nombre y el correo para mostrar al colaborador |
| `StaffMemberships` | `IdentityStaffMemberships` | `Identity/Memberships`: `GrantCollaboratorMembership`, `DeactivateCollaboratorMembership` y `ActivateCollaboratorMembership`; los dos últimos solo tocan membresías con rol `collaborator` |
| `BarbershopSchedule` | `OpeningHoursBarbershopSchedule` | `OpeningHours`: `ListOpenRangesOnWeekday` devuelve las franjas abiertas de un día de la semana (HO-02) |
| `BarbershopServices` | `ServicesBarbershopServices` | `Services`: `ListServices`, para comprobar que cada servicio habilitado existe en la barbería (CO-01) |

El rol y el perfil son cosas distintas: el rol vive en `memberships` (UP-07) y el perfil `collaborators` es lo que tiene agenda. Crear un colaborador común (`CreateCollaboratorWithAccount`) crea la cuenta, la membresía `collaborator` y el perfil en una sola transacción, como `RegisterBarbershop`; si el perfil falla, no queda ni la cuenta ni el rol. Un correo que ya tiene cuenta se rechaza con el error de correo repetido de siempre, porque vincular una cuenta existente es el flujo de invitación (RF-06). El dueño que crea su propio perfil (`CreateOwnCollaboratorProfile`, CO-02) no recibe una membresía nueva y conserva la de `owner`; desactivar su perfil nunca desactiva su membresía (UP-05).

El horario semanal se define completo (`ReplaceSchedule`): `WorkSchedule` es un objeto de valor que rechaza turnos solapados el mismo día, pausas solapadas y pausas fuera de un turno, y el caso de uso rechaza los turnos que no caen dentro de las franjas de la barbería de ese día (HO-02), uniendo las franjas contiguas. Cada rechazo lleva la lista y la posición del elemento (`ScheduleEntryException`), que el controlador traduce a `errores` en `turnos.N` o `pausas.N`. La consulta que leerá la disponibilidad (#53) es `ListCollaboratorsOfferingService`: los colaboradores activos que tienen habilitado un servicio, del más antiguo al más nuevo.

Todavía no se comprueba CO-03 (no desactivar con reservas futuras confirmadas): la tabla `reservations` ya existe (#126), pero la consulta que necesita llega con la creación de reservas (#56); queda anotada en `SetCollaboratorActive`. Obligar el cambio de la contraseña temporal en el primer ingreso (`users.must_change_password`) es del #42.

### La reserva (#126)

`Reservation` es el primer agregado del proyecto y vive en `Src\Reservations\Reservations`. Ofrece el dominio, la persistencia y el puerto `ReservationRepository`; la creación es del #56 (sección siguiente) y la consulta, del #57.

- **Estados y transiciones.** `ReservationStatus` es un `enum` con los siete estados de RE-04 y sabe a cuáles puede pasar (`next()`, `canChangeTo()`, `isFinal()`). El agregado ofrece una operación por transición (`confirm`, `expire`, `complete`, `markNoShow`, `cancelByCustomer`, `cancelByBarbershop`); las que el diagrama no tiene lanzan `TransitionNotAllowedException`, una `DomainException` sin tipo propio, así que responde 409 `conflicto` y no agrega ningún tipo de error. La referencia (`TransitionNotAllowedException` de SodaYa) extiende `BusinessRuleException` con un tipo propio; aquí no se usa porque los tipos estables son una lista cerrada (RNF-17).
- **Historial (RE-09).** Cada transición recibe el instante y el autor como parámetros, porque el dominio nunca consulta la hora. `Author` es una persona (`UserId`, de `Shared`) o el sistema. El agregado acumula sus `ReservationStatusChange` (de, a, autor, fecha) en `pendingChanges`, la creación incluida (de nulo a `temporary`), y `EloquentReservationRepository::save()` los inserta en la misma transacción que el estado y luego llama a `markSaved()`. La referencia guarda solo la fecha de cada estado; aquí hay una tabla de historial porque RE-09 pide también el autor.
- **Copias (SV-03, CA-01).** Al crearla se guardan el precio (`Money`), la duración (`Duration`) y el plazo de cancelación en horas. El intervalo del servicio es un `TimeInterval` y `blockedUntil` es su fin más 5 minutos de limpieza (`Reservation::CLEANUP_MINUTES`, DI-01), calculado en el dominio.
- **Identificadores de otros módulos.** `CollaboratorId`, `ServiceId` y `CustomerMembershipId` se definen dentro de `Reservations` sobre `Identifier`, como `DishId` y `CustomerId` en la referencia: el dominio de un módulo no importa el de otro.
- **Todavía no.** Reprogramar (#59) y cancelar con sus reglas de plazo (#60) no existen; `rescheduleCount` solo se lee. La creación con disponibilidad y concurrencia es del #56 (sección siguiente).

### La creación de la reserva (#56)

`POST /api/v1/barberias/{barberia}/reservas` (`auth:sanctum`, `barbershop:customer`) con `servicio`, `barbero` e `inicio` (ISO 8601 con desfase). El caso de uso es `CreateReservation`, en `Reservations/Application`, y responde 201 con la reserva `confirmada`.

- **Transacción.** Dentro de un punto de guardado (`TransactionRunner`) hace, en este orden: bloquea la fila del colaborador (`SELECT ... FOR UPDATE`, puerto `CollaboratorLock`, caso de uso `LockCollaborator` de `Collaborators`), **vuelve a calcular** la disponibilidad con el mismo cálculo del #53 (`FreeSlots`, que usa `ListAvailableSlots`; nunca confía en una comprobación previa), garantiza la membresía de cliente (`CustomerMemberships`, caso de uso `EnsureCustomerMembership` de `Memberships`), comprueba RE-06 (`hasConfirmedOverlapping`) y guarda la reserva con su historial. La reserva nace temporal y se confirma en la misma operación (RE-07, todavía no hay política de pago): su historial tiene una entrada por la creación y otra por la confirmación. El bloqueo dura hasta que termina la transacción de la petición, así que la solicitud que pierde espera a que la ganadora termine y entonces ya ve su reserva.
- **La membresía nace y muere con la reserva (UP-09).** Se crea dentro de ese punto de guardado. El middleware abre la transacción de la petición, pero el enrutador de Laravel convierte una excepción del controlador en una respuesta *antes* de volver al middleware, así que `BarbershopTransaction` confirmaría igual tras un 409. Lo que garantiza que un 409 no deja ninguna membresía es el punto de guardado del caso de uso, no la transacción de la petición. Una prueba lee la tabla `memberships` con una persona nueva y comprueba que no hay fila.
- **Segunda barrera.** `EloquentReservationRepository::save()` traduce el SQLSTATE `23P01` por el nombre de la restricción: `reservations_no_overlap_excl` es `SlotUnavailableException` (RE-01) y `reservations_customer_no_overlap_excl` es `OverlappingReservationException` (RE-06). Una prueba reemplaza la comprobación por una que siempre dice "libre" y verifica que la base rechaza igual y que no queda membresía.
- **`espacio-no-disponible` con alternativas (RE-02).** `SlotUnavailableException` es una `BusinessRuleException` (409) que implementa `HasProblemMembers`; el caso de uso la atrapa tras revertir, lee hasta 3 alternativas (`ListAlternativeSlots`: mismo día, barbero y servicio, las de inicio más cercano) y la vuelve a lanzar con ellas. El documento trae `alternativas: [{barbero, inicio, fin}]`. Un espacio fuera de la ventana de DI-03 es "no disponible" con `alternativas` vacío. Un barbero inactivo o que no ofrece el servicio también se trata como espacio no disponible (decisión simple; la consulta de disponibilidad responde 422 en ese caso).
- **Solape del cliente (RE-06).** `OverlappingReservationException` usa la regla `reserva-solapada`, que no es un tipo estable, así que responde 409 `conflicto` con su propio `detail`. Se evalúa después de la disponibilidad.
- **Copias (SV-03).** Precio y duración vigentes del servicio (`BookableServices`, caso de uso `GetPublicService`). El plazo de cancelación es `Reservation::DEFAULT_CANCELLATION_DEADLINE_HOURS` (24 h), **provisional hasta el #49**, que lo reemplaza por el de la barbería.
- **404 indistinguibles (UP-06, MT-03).** Una barbería inexistente, un servicio inexistente, inactivo o de otra barbería y un barbero de otra barbería responden con el cuerpo exacto de cualquier recurso inexistente: `ServiceNotBookableException` y `CollaboratorNotFoundException` (de `Reservations`) usan la clave `problems.no-encontrado.detail`. El servicio se busca antes de crear la membresía, así que una barbería inexistente nunca llega a la llave foránea.
- **Persona adulta responsable (CL-05).** Se cumple por construcción: no existen cuentas de menores, la reserva queda a nombre de la cuenta de la sesión (`request->user()`) y el cuerpo no acepta un cliente, así que quien reserva un corte infantil es siempre una persona adulta con cuenta. No hay columna ni campo nuevo; el catálogo tampoco distingue los servicios infantiles.
- **Todavía no.** El pago, los bonos y las reservas asistidas, y la agenda del colaborador (RF-36) y la consulta de reservas (#57). La prueba de carrera con conexiones paralelas reales es el #127.

### La consulta de reservas (#57)

Dos lecturas paginadas de `Reservations`, ambas `GET`, ordenadas por inicio (y por `id` para desempatar) y con `pagina` y `por_pagina` (15 por defecto, hasta 50) en un `meta` con `pagina`, `por_pagina`, `total` y `ultima_pagina`.

| Ruta | Middleware | Quién y qué ve |
| --- | --- | --- |
| `GET /barberias/{barberia}/mis-reservas` | `auth:sanctum`, `barbershop:customer` | El cliente, solo sus reservas (CL-04). Sin membresía de cliente, lista vacía (RD-18). Dueño o colaborador, 403 `prohibido`. Filtros `dia` y `estado` |
| `GET /barberias/{barberia}/reservas` | `auth:sanctum`, `barbershop`, `role:owner,collaborator` | El dueño, las de todos los colaboradores de su barbería, con el filtro `colaborador`. El colaborador, solo las de su perfil (CO-04). Filtros `dia`, `estado` y `colaborador` |

- **Alcance en el caso de uso, no en la petición.** `ListCustomerReservations` y `ListStaffReservations` fijan el cliente o el colaborador del `ReservationFilter` a partir de la sesión; el cuerpo de la petición solo aporta filtros. Un colaborador que pide el `colaborador` de otro, y el dueño que pide uno que no está en su barbería, reciben `CollaboratorNotFoundException`: el mismo 404 de un recurso inexistente (UP-06). Un colaborador sin perfil no tiene agenda y recibe la página vacía, nunca todas las reservas. El dueño que también es colaborador conserva la vista de dueño y puede nombrar su propio perfil; como cliente en su barbería recibe 403 (UP-09).
- **`dia` es una fecha de pared de Costa Rica** (`2030-03-05`). La petición la convierte al instante en que empieza ese día en `America/Costa_Rica` y `ReservationFilter::window()` le suma un día; se compara con el inicio de la reserva (`starts_at >= inicio y < fin del día`), así que una reserva de las 23:30 del 5 es del 5 aunque ya sea el 6 en UTC. `estado` usa los nombres del API (`ReservationStatusNames`, también usados por `ReservationResource`).
- **Sin N+1.** Una consulta de reservas (más el conteo) y lecturas fijas de nombres, sin importar el tamaño de la página: `AttachNames` lee los servicios (`ServiceNames`, caso de uso `ListServices`, también inactivos: SV-04), los colaboradores (`CollaboratorDirectory`, `ListCollaborators`) y, para el personal, los clientes (`CustomerDirectory`: `ListMembershipUsers` y `ListAccountSummaries`). Una página vacía no lee nada. `ListReservationsPaginationTest` compara el número de consultas con 1 y con 50 filas.
- **Qué expone.** `ReservationListItemResource` (`ReservaDeLista`): `id`, `servicio`, `servicio_nombre`, `barbero`, `barbero_nombre`, `estado`, `inicio`, `fin`, `precio` y `duracion_minutos`. `StaffReservationListItemResource` (`ReservaDeAgenda`) agrega `cliente_nombre`; nunca el correo ni el teléfono (CO-04). Los ids se mantienen para que el cliente web reutilice el tipo de `Reserva`; el plazo de cancelación queda para el detalle (#58).
- **Paginación de la referencia.** `Page`, `PageRequest`, `ListRequest`, `PageMeta` y `EloquentPages` no existían en `Shared`: se incorporaron tal cual de la referencia (el texto de `shared.page_out_of_range` y los atributos `pagina` y `por_pagina` incluidos). Divergencia con la referencia: el filtro `estado` se lee con `filled()` y no con `has()`, para que `?estado=` vacío no falle.
- **Aislamiento.** `reservations` declara la operación `LIST` en `TenantResources` y la ruta `mis-reservas` entra por `CUSTOMER_ROUTES` y por la nueva lista `otherPaths` de `TenantResource`, porque no cuelga de la ruta de la colección. Todavía no hay cancelar (#60) ni reprogramar (#59).

### El detalle de una reserva (#58)

`GET /barberias/{barberia}/reservas/{reserva}` (`auth:sanctum`, `barbershop`), una sola ruta con tres proyecciones según el rol que fijó el middleware. `ReservationController::show` elige el caso de uso; ninguno recibe el rol del cliente de la petición.

| Rol | Caso de uso | Recurso (esquema) | Qué ve |
| --- | --- | --- | --- |
| Cliente | `GetCustomerReservation` | `CustomerReservationDetailResource` (`ReservaDetalleDeCliente`) | Su reserva con los campos del listado y `plazo_cancelacion_horas`. Sin saldo (PA-10), sin historial, sin nombre de cliente |
| Colaborador | `GetStaffReservation` | `StaffReservationDetailResource` (`ReservaDetalleDeAgenda`) | Las de su propia agenda, con `cliente_nombre` y `saldo_pendiente` (CO-04). Sin `historial` |
| Dueño | `GetStaffReservation` | el mismo | Cualquiera de su barbería, además con `historial`: `de`, `a`, `autor` y `fecha` |

- **404 uniforme (UP-06).** `ReservationNotFoundException` cubre la reserva inexistente, la de otra barbería (el repositorio filtra por la barbería y la política de filas es la segunda barrera), la de otro cliente y la de otro colaborador. Quien no tiene rol en la barbería recibe el 404 del middleware.
- **Divergencia con el plan: `barbershop` sin `:customer`.** El modo `customer` deja pasar a quien no tiene rol y rechaza con 403 al personal, y una sola ruta no puede tener dos pilas de middleware. El modo normal ya da 404 sin rol, y los tres roles existentes están permitidos, así que no hace falta `role:`. Por eso un cliente sin rol recibe 404 y no una lista vacía: no es una lectura de datos propios en lote.
- **Saldo pendiente (PA-10).** Todavía no hay pagos ni depósitos: el saldo es el precio completo de la reserva, que se cobra en el local. Cuando exista el depósito del #49 se descuenta aquí. No se agregaron columnas ni tablas; el detalle de pagos y los ingresos tampoco se exponen.
- **Historial (RE-09).** `ReservationRepository::history()` lee `reservation_status_history` por reserva, de la primera a la última entrada. Los autores se resuelven por nombre con `AuthorDirectory` (adaptador `UsersAuthorDirectory` sobre `ListAccountSummaries`), en una sola lectura de cuentas sin importar cuántas entradas haya; el sistema no tiene nombre y se muestra como `sistema` (`reservations.author_system`). Nunca se expone el correo.
- **Aislamiento.** `reservations` suma la operación `SHOW` en `TenantResources`.

### La disponibilidad (#53)

`Availability/Slots` no tiene tablas ni estado: calcula los espacios libres de un servicio en un día con datos que ya existen. Su dominio es puro y recibe esos datos cargados, más la hora actual como parámetro. La ruta pública es del #55 (más abajo).

- **Modelo de lectura.** `DayAgenda` reúne, para un colaborador y una fecha (`CalendarDate`), las franjas abiertas de la barbería, sus turnos y pausas (horas de pared) y los intervalos que ocupan las reservas (instantes). `SlotCalculator::forAgenda()` devuelve una lista de `Slot` (colaborador, inicio y fin del servicio, sin la limpieza) y `nearest()` elige los más cercanos a una hora pedida.
- **Reglas.** Un espacio de `servicio + 5 min` debe caber en un turno del colaborador y en el horario de la barbería (se unen los rangos contiguos), no tocar una pausa ni solapar una reserva (DI-01); las reservas contiguas se permiten. Los espacios empiezan en los cuartos de hora del reloj (:00, :15, :30, :45), no en el inicio del turno (DI-02). Los límites de DI-03 son inclusivos: exactamente 60 minutos de anticipación y exactamente 30 días sí se pueden reservar. Las reservas temporales cuentan como ocupadas (DI-05); la lista de espera es un issue diferido. Un servicio solo lo ofrecen los colaboradores activos que lo tienen habilitado (CO-01).
- **Zona horaria (HO-03).** `CalendarDate` es el único lugar donde una hora de pared de Costa Rica se vuelve un instante (`at(minutos)`) y donde un instante se vuelve un día (`fromMoment()`); el resto del cálculo compara instantes o minutos del día. No hay horario de verano, así que sumar minutos a la medianoche es exacto. Las pruebas cubren los bordes: una reserva que cruza la medianoche, la anticipación que cambia de día y el último espacio del día.
- **Puertos** (en `Slots/Application/Contracts`, cada uno con un adaptador en `Slots/Infrastructure` que invoca un caso de uso del otro módulo): `ServiceCatalog` (`GetBookableDuration`, de `Services`; un servicio inexistente o inactivo no se puede reservar, SV-04), `BarbershopHours` (`ListOpenRangesOnDate`, de `OpeningHours`, que ya considera los cierres), `StaffRoster` (`ListStaffWorkingOn`, de `Collaborators`: los activos que ofrecen el servicio y trabajan ese día de la semana, con sus turnos y pausas) y `BlockedIntervals` (`ListBlockedIntervals`, de `Reservations`).
- **Casos de uso.** `ListAvailableSlots` (un colaborador o todos los que ofrecen el servicio) y `ListAlternativeSlots` (hasta 3 espacios del mismo día y servicio, los de inicio más cercano, RE-02; lo usa la creación de reservas, #56). "Cualquiera disponible" (DI-04) es el #54 y no está hecho, pero la consulta por colaborador ya devuelve lo que necesita.
- **Sin N+1.** Una consulta de un día lee el servicio, el horario y los cierres, los colaboradores con sus hijos (tres consultas para todos, no por colaborador) y **una** consulta de reservas con `collaborator_id IN (...)` acotada a ese día. Se detiene antes de leer el personal y las reservas si la barbería está cerrada. Una prueba de integración comprueba que el número de consultas es el mismo con 2 y con 6 colaboradores.
- **Pendiente.** La reserva temporal (RE-05) caduca a los 10 minutos, pero `reservations.expires_at` no existe todavía (llega con los pagos, #62), así que hoy toda reserva temporal cuenta como bloqueo. Cuando exista la columna, `EloquentBlockedIntervalReader` debe excluir las temporales vencidas. Tampoco se consideran las ausencias del colaborador (CO-06), cuya tabla `collaborator_absences` no está creada.

### La consulta pública de disponibilidad (#55)

`GET /api/v1/barberias/{barberia}/disponibilidad?servicio=&barbero=&fecha=` es pública (`barbershop:public`, registrada en `PUBLIC_ROUTES` del arnés de aislamiento). Lo atiende el caso de uso `SearchAvailability`, que se apoya en `ListAvailableSlots`.

- **Respuesta.** El servicio con su duración y su precio con el IVA desglosado, la `fecha` y los espacios agrupados por barbero (`barberos[].espacios[]`, con `inicio` y `fin` en ISO 8601 con `-06:00` y el `barbero` que se envía al reservar). Con `barbero` trae un solo grupo; sin él, un grupo por cada colaborador que ofrece el servicio y tiene espacios. No incluye imagen (#46).
- **Días siguientes.** Si la fecha no tiene espacios, `dias_siguientes` trae hasta 3 días posteriores con espacios, buscando solo dentro de la ventana de DI-03 y deteniéndose al encontrar los 3. Una fecha fuera de la ventana devuelve la lista vacía y no busca. Lee el servicio y los nombres una vez, y cada día que revisa lee cierre, horario, personal (4 consultas) y reservas: en el peor caso, 31 días × 7 consultas más 7 fijas. La asignación de "cualquiera disponible" (#54) y la lista de espera (diferida) no están.
- **Validaciones.** Forma en `AvailabilityRequest` (422 con el campo). Un servicio inexistente, inactivo o de otra barbería, y un barbero que no es de la barbería, dan el mismo 404 `no-encontrado`; un barbero de la barbería que está inactivo o no ofrece el servicio da 422 `datos-invalidos` (`CollaboratorNotBookableException`).
- **Puertos nuevos.** `ServiceCatalog::bookableService()` (caso `GetPublicService` de `Services`) y `StaffRoster::bookableNames()` (caso `ListBookableStaff` de `Collaborators`, que solo expone el nombre de la cuenta, nunca el correo).

### El tiempo es un parámetro

El dominio nunca pregunta la hora. El momento actual se obtiene en el borde (HTTP o la tarea en cola) a través del contrato de reloj de `Src\Shared\Domain\Contracts`, en la zona horaria de la aplicación (`America/Costa_Rica`), y viaja como `DateTimeImmutable` hasta el caso de uso y el dominio. En las pruebas el reloj se sustituye por uno fijo. Así las reglas que dependen del reloj (plazo de cancelación, expiración de la reserva temporal, mínimo para ofrecer un espacio) se prueban con momentos fijos, sin arrancar el framework.

### Cuentas y autenticación

El contexto `Identity` guarda las cuentas en `Identity/Users` y los roles en `Identity/Memberships`. Una cuenta es global: un correo, una contraseña, y puede tener un rol distinto en cada barbería (UP-07). La membresía une a la persona con una barbería y su rol: `owner`, `collaborator` o `customer`.

El agregado de la cuenta es PHP puro. Puertos lo separan del framework: `UserRepository`, con su adaptador Eloquent; `PasswordHasher`, cuyo adaptador usa la fachada `Hash` de Laravel; `TokenIssuer` y `TokenRevoker`, con adaptadores sobre los tokens personales de Sanctum; y `LoginAttempts`, con un adaptador sobre el limitador de Laravel. `UserModel` vive en la infraestructura, extiende el usuario autenticable de Laravel y usa `HasApiTokens` de Sanctum; `config/auth.php` lo declara como proveedor del guard `sanctum`.

El ingreso (`POST /api/v1/tokens`) entrega un token personal por dispositivo; los cierres de sesión (`DELETE /api/v1/tokens/actual` y `DELETE /api/v1/tokens`) lo revocan y exigen `auth:sanctum`. El token identifica a la persona y no lleva permisos por rol: el rol es por barbería y se verifica en cada petición. Tras 5 fallos seguidos con el mismo correo desde la misma dirección, `LoginAttempts` bloquea nuevos intentos durante 15 minutos contados desde el quinto fallo (SE-05); el bloqueo responde 429 `demasiadas-solicitudes` con `Retry-After`, incluso si la contraseña es correcta. Los contadores viven en la caché.

La membresía de cliente se crea al primer uso de una operación de cliente en la barbería, en la misma transacción que la operación (UP-09). Ver [ADR 0010](adr/0010-customer-membership-created-on-first-use.md).

### Aislamiento entre barberías

En BarberHub una persona puede tener roles en varias barberías, así que la barbería no se deduce de la persona, como en el proyecto de referencia, sino de la ruta y se verifica contra sus membresías (RD-18). Ver [ADR 0005](adr/0005-tenancy-through-a-port-and-row-level-security.md).

1. En las rutas, `{barberia}` es el UUID v7 de la barbería. La barbería nunca se toma del cuerpo, de la consulta ni de un encabezado. El enlace público solo sirve para resolverla, con la ruta pública `GET /api/v1/enlaces/{enlace}`, que devuelve el UUID y los datos públicos de la barbería.
2. El middleware `barbershop` comprueba que la sesión tenga un rol en esa barbería, por el puerto `BarbershopRoles` de `Shared`, y recién entonces fija el contexto: la barbería y el rol quedan en los atributos de la solicitud, el puerto `BarbershopContext` de `Shared` los lee y la petición corre dentro de una transacción que fija la barbería en PostgreSQL. Las rutas públicas usan `barbershop:public`, que fija la barbería de la ruta sin sesión. Esa transacción no sustituye a `TransactionRunner`: las de los casos de uso son puntos de guardado dentro de ella.
3. Si la persona no tiene rol en la barbería, o el recurso pertenece a otra, la respuesta es 404 `no-encontrado`, con el mismo cuerpo que un recurso inexistente (UP-06). Si tiene rol pero insuficiente, 403 `prohibido`. El middleware `role` (`RequireRole`, en `Shared`) lo aplica: lee el rol que fijó `barbershop`, así que va después de él (`['auth:sanctum', 'barbershop', 'role:owner']`), y responde 403 si el rol no está entre los permitidos o no hay rol. Por ahora solo lo usan las rutas de administración de `Services`; el #43 lo amplía con la matriz de permisos y sus pruebas. A diferencia del proyecto de referencia, el permiso no viaja en las abilities del token de Sanctum, porque el rol depende de la barbería de la ruta.
4. Cada método de un repositorio filtra por `barbershop_id`: buscar, listar y borrar. La barbería llega como parámetro o, como en `Services` (#45), el repositorio la obtiene del puerto `BarbershopContext` por constructor; en ambos casos el filtro del código es la primera barrera. Las reglas `exists` y `unique` de los Form Requests también filtran por la barbería.
5. PostgreSQL es la segunda barrera: las políticas de seguridad a nivel de fila ocultan las filas de otras barberías aunque una consulta olvide el filtro. El detalle está en [database.md](database.md).

La regla tiene tres excepciones:

| Excepción | Qué ocurre |
| --- | --- |
| Operaciones de cliente que crean la primera membresía (crear reserva, comprar bono, anotarse en la fila de espera) | Una persona sin rol en la barbería puede hacerlas; el sistema crea su membresía de cliente en la misma transacción. Un dueño o colaborador de esa barbería recibe 403 `prohibido` |
| Propuesta de IA | La puede pedir cualquier persona autenticada sin rol; no crea la membresía ni guarda datos. Un dueño o colaborador recibe 403 `prohibido` |
| Lecturas de datos propios (mis reservas, mis bonos, mis filas) | Sin rol en la barbería devuelven una lista vacía, no un error |

La primera excepción implementada es el modo `barbershop:customer` de `ScopeRequestToBarbershop`, que usa `POST /barberias/{barberia}/reservas`: con rol de dueño o colaborador en esa barbería responde 403 `prohibido` antes de validar nada, con rol de cliente o sin rol deja pasar, y la membresía de cliente la crea el caso de uso dentro de su propia transacción (UP-09, [ADR 0010](adr/0010-customer-membership-created-on-first-use.md)). Esas rutas se declaran en `TenantResources::CUSTOMER_ROUTES` y la auditoría de cobertura exige ese modo. Una persona con un rol inactivo de staff en la barbería pasa el middleware, pero `EnsureCustomerMembership` la rechaza con 403 porque tiene un único rol por barbería (UP-07). La tercera excepción (mis reservas, #57) usa el mismo modo en `GET /barberias/{barberia}/mis-reservas`: dueño y colaborador reciben 403, y quien no tiene rol de cliente recibe la lista vacía porque el caso de uso no encuentra su membresía; la lectura nunca la crea.

Las rutas públicas son la resolución del enlace, la lista de servicios activos y la consulta de disponibilidad, y solo exponen datos públicos.

El primer recurso con `barbershop_id` es `Services` (#45) y fija el reparto de rutas: lo público en `/barberias/{barberia}/servicios` (`barbershop:public`, solo los servicios activos) y lo del dueño en `/barberias/{barberia}/administracion/servicios` (`auth:sanctum`, `barbershop`, `role:owner`), igual que la referencia separa el menú público de la cocina. Un servicio nunca se borra, así que no hay `DELETE`: se inactiva con `PATCH` y `activo: false`. Los precios incluyen el IVA de 13 % y los recursos de respuesta traen `precio`, `base` e `iva`; el cálculo vive en el dominio (`ServicePrice`). Los listados de `Services` son simples, sin paginación, porque `context/` no la exige. La lista pública de una barbería que no existe es una lista vacía, porque `barbershop:public` no comprueba la barbería.

La suite `tests/Feature/Tenancy` (el mecanismo desde el #38; un caso por cada recurso, con el #123) lo comprueba con dos barberías. Un recurso nuevo se registra en `tests/Support/Tenancy/TenantResources.php`: la suite lo prueba contra una segunda barbería y falla si una tabla o una ruta de una barbería no figura allí. Además agrega su caso a la matriz de permisos.

**Pendiente:** los webhooks, las tareas en cola y las tareas programadas no tienen persona autenticada. Cómo fijan la barbería, y si hace falta un tercer rol de base de datos de mantenimiento, se decide antes de M08. Ver "Pendientes" en [database.md](database.md).

### Concurrencia

| Garantía | Dónde se cumple |
| --- | --- |
| Dos solicitudes por el mismo espacio: una gana, la otra recibe `espacio-no-disponible` | `CreateReservation` bloquea la fila del colaborador (`SELECT ... FOR UPDATE`) y revalida la disponibilidad (#56); la restricción `EXCLUDE` de PostgreSQL es la segunda barrera y su violación se traduce a `espacio-no-disponible`. La prueba de carrera con conexiones paralelas es el #127 (RE-01, RE-02) |
| El saldo del bono nunca es negativo | Decremento atómico y condicional (`UPDATE ... WHERE remaining_sessions > 0`) más `CHECK (remaining_sessions >= 0)` (previsto, #89) (BO-03) |
| Un evento de la pasarela se procesa una sola vez | Llave única `(gateway, event_id)` en `webhook_events` (previsto, #65) (PA-05) |
| Cancelar es idempotente | El caso de uso revisa el estado actual dentro de la transacción (previsto, #60) (CA-06) |

## Errores

El dominio lanza excepciones que extienden `Src\Shared\Domain\Exceptions\DomainException`. Cada excepción lleva una clave de traducción, no un texto: el mensaje en español se resuelve en la infraestructura desde `lang/es` (ver [ADR 0008](adr/0008-user-messages-from-translation-files.md)).

Toda respuesta de error sigue RFC 9457 (`application/problem+json`). `ProblemDetailsRenderer` convierte cada excepción en un documento con `type`, `title`, `status`, `detail` e `instance`:

| Excepción | Tipo de problema | HTTP |
| --- | --- | --- |
| Validación de un Form Request, `InvalidValueException` | `datos-invalidos`, con `errores` por campo | 422 |
| `NotFoundException`, o recurso de otra barbería | `no-encontrado` | 404 |
| `AuthenticationFailedException` | `credenciales-invalidas` | 401 |
| Ruta protegida sin un token válido | `no-autenticado` | 401 |
| Persona autenticada sin permiso suficiente | `prohibido` | 403 |
| Otra excepción del dominio sin tipo propio | `conflicto` | 409 |
| Espacio ocupado al reservar | `espacio-no-disponible`, con hasta 3 alternativas | 409 |
| Última sesión del bono ya tomada | `saldo-insuficiente` | 409 |
| Cancelación o reprogramación fuera del plazo | `plazo-vencido` | 409 |
| Bono vencido | `bono-vencido` | 409 |
| Oferta vencida | `oferta-vencida` | 409 |
| Cualquier otra | `error-interno`, sin detalles técnicos | 500 |

**Miembros adicionales (extensión de la referencia).** La referencia solo agrega `errores` a la validación. Para un error de dominio con datos propios, como las alternativas de `espacio-no-disponible` (RE-02), la excepción implementa `Src\Shared\Domain\Exceptions\HasProblemMembers` y devuelve en `problemMembers()` los miembros extra con nombre en español y valores simples; `ProblemDetailsRenderer` los agrega al documento sin dejar que reemplacen `type`, `title`, `status`, `detail` ni `instance`. `Shared` no conoce la excepción concreta, y el dominio no conoce HTTP.

Los cinco tipos de negocio (RNF-17) responden 409. Un 403 de dominio es una `BusinessRuleException` cuya regla es `prohibido`. El campo `instance` repite el identificador de la solicitud, que también viaja en el encabezado `X-Request-Id` y en cada línea de log.

## Contrato de la API

Scramble genera el contrato OpenAPI desde el código. La documentación interactiva está en `/docs/api` y el contrato se versiona en `barberhub-api/openapi/v1.json`. Tras cambiar un endpoint hay que regenerarlo con `composer openapi`: una prueba falla si el archivo versionado difiere del código. Los tipos del cliente web se generan desde ese contrato.

## Rutas

`routes/api.php` incluye el archivo de rutas de cada módulo. Todas se sirven bajo `/api/v1`, prefijo que se configura una sola vez en `bootstrap/app.php`. Un cambio incompatible se publica como `/api/v2`. Las rutas llevan nombres en español, por ejemplo `/barberias/{barberia}/...`.

Cada ruta con `{barberia}` restringe el parámetro con `whereUuid('barberia')`. Las únicas rutas públicas de una barbería son la resolución del enlace, la lista de servicios activos y la consulta de disponibilidad (RD-18).

La ruta de salud `GET /salud` es pública, no pasa por ningún middleware y está fuera de `/api/v1`, porque no es una ruta de negocio. Responde 200 si la base de datos y la cola funcionan y 503 en caso contrario, sin detalles (RNF-14). `bootstrap/app.php` la carga con `then:` desde `src/Shared/Infrastructure/Http/health.php`; el caso de uso `CheckHealth` consulta los puertos `DatabaseHealth` y `QueueHealth`.

## Pruebas

| Carpeta | Alcance |
| --- | --- |
| `tests/Unit` | Dominio y casos de uso, sin arrancar el framework. |
| `tests/Feature` | Endpoints y adaptadores contra PostgreSQL, con el rol de la aplicación y solo sobre `barberhub_testing`. `tests/Feature/Tenancy` (#38 y #123) reúne las pruebas de aislamiento entre barberías. |
| `tests/Architecture` | Regla de dependencias entre capas y entre módulos. |

La concurrencia se prueba con conexiones paralelas reales (RNF-05). Las pasarelas, el proveedor de IA y el correo se simulan.

## Convenciones, base de datos y decisiones

Las convenciones de idioma y de nombres están en [ubiquitous-language.md](ubiquitous-language.md). Los roles, las migraciones y el esquema están en [database.md](database.md) y el modelo de datos, en [data-model.md](data-model.md). El motivo de cada decisión de arquitectura está en [adr/](adr/).
