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
| Catalog | `Barbershops`, `Services`, `OpeningHours`, `Collaborators` |
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
3. Si la persona no tiene rol en la barbería, o el recurso pertenece a otra, la respuesta es 404 `no-encontrado`, con el mismo cuerpo que un recurso inexistente (UP-06). Si tiene rol pero insuficiente, 403 `prohibido`.
4. Cada método de un repositorio recibe el `BarbershopId` y filtra por `barbershop_id`: buscar, listar y borrar. Las reglas `exists` y `unique` de los Form Requests también filtran por la barbería.
5. PostgreSQL es la segunda barrera: las políticas de seguridad a nivel de fila ocultan las filas de otras barberías aunque una consulta olvide el filtro. El detalle está en [database.md](database.md).

La regla tiene tres excepciones:

| Excepción | Qué ocurre |
| --- | --- |
| Operaciones de cliente que crean la primera membresía (crear reserva, comprar bono, anotarse en la fila de espera) | Una persona sin rol en la barbería puede hacerlas; el sistema crea su membresía de cliente en la misma transacción. Un dueño o colaborador de esa barbería recibe 403 `prohibido` |
| Propuesta de IA | La puede pedir cualquier persona autenticada sin rol; no crea la membresía ni guarda datos. Un dueño o colaborador recibe 403 `prohibido` |
| Lecturas de datos propios (mis reservas, mis bonos, mis filas) | Sin rol en la barbería devuelven una lista vacía, no un error |

Las rutas públicas son la resolución del enlace, la lista de servicios activos y la consulta de disponibilidad, y solo exponen datos públicos.

La suite `tests/Feature/Tenancy` (el mecanismo desde el #38; un caso por cada recurso, con el #123) lo comprueba con dos barberías. Un recurso nuevo agrega su caso a esa suite y a la matriz de permisos.

**Pendiente:** los webhooks, las tareas en cola y las tareas programadas no tienen persona autenticada. Cómo fijan la barbería, y si hace falta un tercer rol de base de datos de mantenimiento, se decide antes de M08. Ver "Pendientes" en [database.md](database.md).

### Concurrencia

| Garantía | Dónde se cumple |
| --- | --- |
| Dos solicitudes por el mismo espacio: una gana, la otra recibe `espacio-no-disponible` | Transacción con `SELECT ... FOR UPDATE` en el caso de uso y restricción `EXCLUDE` de PostgreSQL como segunda barrera (previsto, #56 y #127) (RE-01, RE-02) |
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
