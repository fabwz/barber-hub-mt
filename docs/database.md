# Base de datos: PostgreSQL 18

BarberHub usa PostgreSQL 18 como único motor, en desarrollo, en pruebas y en producción. Este documento fija cómo se conecta la aplicación y cómo se crean y versionan los objetos de la base.

> **Estado:** documento inicial (issue #26). Los roles, las bases, la migración de permisos y las pruebas de convenciones ya existen (#17 y #18). Lo marcado "(previsto, #N)" lo crea su issue y entonces se actualiza este archivo. Los nombres de políticas y tipos siguen la convención del proyecto de referencia.

## Versión

La versión mínima es PostgreSQL 18. Una prueba falla si el servidor es anterior, porque el proyecto usa funciones que no existen antes de esa versión, como `uuidv7()`.

## Bases de datos

| Base | Para qué |
| --- | --- |
| `barberhub` | Desarrollo y despliegue |
| `barberhub_testing` | Exclusivamente la suite de PHPUnit |

`phpunit.xml` fija `barberhub_testing`, así que las pruebas nunca tocan la base de desarrollo. Los traits como `RefreshDatabase` borran datos: nunca se ejecutan contra `barberhub`.

## Roles

La aplicación nunca se conecta con el rol dueño de las tablas.

| Rol | Conexión de Laravel | Qué hace | Qué no puede hacer |
| --- | --- | --- | --- |
| `barberhub_owner` | `pgsql_admin` | Es dueño de las bases y ejecuta las migraciones | No atiende peticiones |
| `barberhub_app` | `pgsql` (por defecto) | Lee y escribe filas | Crear, alterar o borrar tablas; no es superusuario ni tiene `BYPASSRLS` |

El script `database/roles.sql` crea los dos roles y las dos bases. Lo ejecuta una sola vez un superusuario, con las contraseñas como variables de `psql`: nunca se versionan.

La primera migración otorga al rol de la aplicación, con `ALTER DEFAULT PRIVILEGES`, los permisos de lectura y escritura sobre toda tabla y secuencia que el dueño cree después. Ninguna tabla nueva necesita un paso manual.

Por qué importa: en PostgreSQL los superusuarios y los roles con `BYPASSRLS` no pasan por las políticas de seguridad a nivel de fila, y el dueño de una tabla tampoco, salvo que la tabla las fuerce. Las tablas de una barbería las fuerzan, pero la aplicación igual se conecta con un rol que no es dueño ni tiene `BYPASSRLS`: el aislamiento no depende de una sola línea de una migración.

## Migraciones

- Se ejecutan siempre con el rol dueño: `php artisan migrate --database=pgsql_admin`.
- Cada migración tiene su `down()` y una prueba las ejecuta hacia atrás y hacia adelante sobre `barberhub_testing`.
- Un cambio que rompe la versión anterior del código se hace en dos despliegues: primero se expande el esquema y, cuando el código viejo ya no corre, se contrae.

## Convenciones del esquema

| Tema | Convención |
| --- | --- |
| Nombres | Inglés, `snake_case`; tablas en plural |
| Llave primaria | `id` de tipo `uuid`, versión 7, con `DEFAULT uuidv7()` |
| Barbería | Toda tabla que pertenece a una barbería lleva `barbershop_id` |
| Llaves únicas y foráneas | Incluyen `barbershop_id`, para que el aislamiento no dependa del código |
| Fechas | `timestamptz`; la sesión trabaja en `America/Costa_Rica` |
| Horas de pared | `time` sin zona (horarios semanales); se leen en hora de Costa Rica |
| Dinero | Colones enteros en `integer`; nunca decimales ni punto flotante |
| Rangos del negocio | Restricción `CHECK` con nombre `<tabla>_<columna>_check` |
| Intervalos que no se traslapan | Restricción `EXCLUDE` con nombre `<tabla>_no_overlap_excl` |
| Estados | `varchar` con `CHECK` sobre la lista cerrada de valores, en inglés y `snake_case` |
| Normalización | Tercera forma normal; cada excepción se justifica en [data-model.md](data-model.md) |

La aplicación genera el identificador antes de guardar, así el agregado tiene identidad desde que nace. El `DEFAULT uuidv7()` es el respaldo para las filas que no pasan por la aplicación.

## Objetos programables

El constructor de esquemas de Laravel no cubre funciones, procedimientos, triggers, políticas ni vistas. Se crean dentro de una migración con `DB::statement()` o `DB::unprepared()`, y su `down()` los elimina.

| Objeto | Prefijo | Regla |
| --- | --- | --- |
| Función | `fn_` | `SECURITY INVOKER` y `search_path` fijo |
| Procedimiento | `sp_` | Solo para tareas por lotes; se invoca con `CALL` fuera de una transacción |
| Trigger | `trg_` | `BEFORE` por fila para validar o completar; `AFTER` por fila para propagar a otras tablas |
| Vista | `v_` | `security_invoker = true`, para que apliquen las políticas de quien consulta |
| Vista materializada | `mv_` | Índice único para refrescar con `CONCURRENTLY`; se consulta a través de una vista |
| Política | `<tabla>_barbershop_isolation` | Una por tabla de una barbería, con la misma expresión en `USING` y `WITH CHECK` |

## Extensiones y tipos (previsto, #47 y #56)

| Objeto | Para qué |
| --- | --- |
| Extensión `btree_gist` | Permite comparar con `=` columnas escalares (`uuid`, `smallint`) dentro de un índice GiST, necesario para las restricciones `EXCLUDE` |
| Tipo `timerange` | Rango de `time` para los horarios semanales, creado con `CREATE TYPE timerange AS RANGE (subtype = time)`; PostgreSQL no lo trae incorporado |

`btree_gist` es una extensión de confianza: el rol dueño de la base la instala sin ser superusuario. `php artisan migrate:fresh` borra las tablas pero no los tipos, por eso la migración elimina `timerange` antes de crearlo.

## Restricciones de exclusión (previsto, #47 y #56)

Una restricción `UNIQUE` solo compara por igualdad. Cuando la regla es «no se traslapan», se usa `EXCLUDE`, que acepta un operador por columna. Dos usos previstos:

```sql
-- Franjas del horario de una barbería (HO-01): mismo día, sin minutos en común
ALTER TABLE opening_hours ADD CONSTRAINT opening_hours_no_overlap_excl EXCLUDE USING gist (
    barbershop_id WITH =,
    day_of_week WITH =,
    timerange(opens_at, closes_at) WITH &&
);

-- Reservas de un colaborador (RE-01): el intervalo incluye la limpieza de 5 minutos
ALTER TABLE reservations ADD CONSTRAINT reservations_no_overlap_excl EXCLUDE USING gist (
    barbershop_id WITH =,
    collaborator_id WITH =,
    tstzrange(starts_at, blocked_until) WITH &&
) WHERE (status IN ('temporary', 'confirmed'));
```

El rango se construye con los límites por defecto `[)`: el inicio está incluido y el fin excluido. La violación devuelve el SQLSTATE `23P01`, que el repositorio traduce a la excepción del dominio (`espacio-no-disponible` en el caso de las reservas).

La limpieza se guarda ya sumada en `blocked_until`, porque una expresión de índice debe ser inmutable y sumar un intervalo a un `timestamptz` no lo es. Ver la excepción deliberada en [data-model.md](data-model.md).

Una reserva temporal vencida sigue ocupando el espacio hasta que la tarea de expiración cambia su estado (retraso máximo de 1 minuto, NO-04). Por eso el caso de uso de reservar expira, dentro de su propia transacción, las reservas temporales vencidas del mismo colaborador antes de insertar.

## Seguridad a nivel de fila

Es la segunda barrera del aislamiento entre barberías. La primera está en el código: cada repositorio filtra por `barbershop_id`. Si una consulta olvida ese filtro, PostgreSQL igual devuelve solo las filas de la barbería en curso.

### Qué protege

Toda tabla con `barbershop_id` (salvo las excepciones de abajo) tiene la seguridad a nivel de fila habilitada y forzada, con una política por tabla:

```sql
ALTER TABLE services ENABLE ROW LEVEL SECURITY;
ALTER TABLE services FORCE ROW LEVEL SECURITY;
CREATE POLICY services_barbershop_isolation ON services
    USING (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid)
    WITH CHECK (barbershop_id = NULLIF(current_setting('app.barbershop_id', true), '')::uuid);
```

| Parte | Qué hace |
| --- | --- |
| `ENABLE ROW LEVEL SECURITY` | Aplica las políticas a todo rol que no sea el dueño de la tabla; sin una política que lo permita, no se ve ninguna fila |
| `FORCE ROW LEVEL SECURITY` | Las aplica también al dueño, `barberhub_owner` |
| `USING` | Decide qué filas existentes se pueden leer, actualizar o borrar |
| `WITH CHECK` | Valida cada fila insertada o modificada: no se puede crear ni mover una fila a otra barbería |
| `current_setting('app.barbershop_id', true)` | Lee la barbería de la transacción; con `true` devuelve `NULL` en lugar de fallar si la variable no existe |
| `NULLIF(..., '')` | Convierte el texto vacío en `NULL`; `barbershop_id = NULL` nunca es verdadero, así que sin barbería no se ve ninguna fila |

Una escritura que no cumple `WITH CHECK` falla con el SQLSTATE `42501`. Un `UPDATE` o un `DELETE` sin barbería no falla: no encuentra filas.

### Cómo se fija la barbería

La barbería vive en la variable `app.barbershop_id` y se fija con `set_config('app.barbershop_id', ?, true)`. El tercer argumento la limita a la transacción en curso: al confirmar o deshacer, desaparece, y una conexión reutilizada nunca hereda la barbería de la petición anterior. Después de su primera transacción, la variable de esa conexión queda como texto vacío y no como `NULL`; por eso la política usa `NULLIF`.

`BarbershopTransaction`, en `Src\Shared\Infrastructure\Tenancy`, abre una transacción y fija la barbería antes de cualquier consulta; con `null` fija el texto vacío y no se alcanza ninguna fila. El middleware `barbershop` (`ScopeRequestToBarbershop`) la usa. Las transacciones de los casos de uso, anidadas dentro de la suya, son puntos de guardado, así que `DatabaseTransactionRunner` no cambia.

| Rutas | Middleware | Barbería de la transacción |
| --- | --- | --- |
| Con `{barberia}` y sesión | `auth:sanctum` y `barbershop` | La de la ruta, solo si la persona tiene una membresía activa en ella |
| Públicas (servicios activos y disponibilidad) | `barbershop:public` | La de la ruta, sin sesión y sin verificar membresía |
| `GET /api/v1/enlaces/{enlace}` | Ninguno | No fija barbería: lee `barbershops` por su enlace público |

Las rutas con `{barberia}` restringen el parámetro con `whereUuid('barberia')`; un identificador mal formado no encuentra ruta y responde el 404 genérico.

En el modo por defecto, el middleware consulta la membresía **antes** de fijar la barbería (por el puerto `BarbershopRoles`, cuyo adaptador lee `memberships`; por eso esa tabla no tiene política). Una persona sin rol, o con una barbería inexistente, recibe 404 `no-encontrado` antes de tocar las tablas, con el mismo cuerpo que un recurso inexistente. Con rol, el middleware guarda `barbershop_id` y `barbershop_role` en los atributos de la solicitud (de ahí lee el puerto `BarbershopContext`), ejecuta la petición dentro de la transacción y quita los atributos al terminar. El modo público deja el rol vacío.

Las excepciones de RD-18 (operaciones de cliente que crean la primera membresía, propuesta de IA y lecturas propias) no son modos todavía: el #56 y el #57 las agregan.

Como el dueño de las tablas también está sujeto a las políticas, los sembradores escriben los datos de las barberías de demostración dentro de `BarbershopTransaction`, y en las pruebas cada fábrica fija la barbería de su fila antes de insertarla.

### Excepciones

| Tabla | Por qué no tiene política |
| --- | --- |
| `users` | Las cuentas son globales: el ingreso busca a la persona por su correo antes de saber a qué barbería pertenece |
| `barbershops` | No tiene `barbershop_id`: cada fila es una barbería, y se busca por su enlace público o por el identificador de la ruta |
| `memberships` | Se lee antes de conocer la barbería: para verificar el rol de la persona en `{barberia}` y para listar sus barberías (UP-08). Se protege por código: toda consulta filtra por `user_id` |
| `webhook_events` | El aviso de la pasarela llega sin sesión y antes de saber a qué barbería pertenece el pago. **Pendiente, ver abajo** |
| `personal_access_tokens`, `password_reset_tokens` | Tablas de Laravel y Sanctum asociadas a la cuenta, no a una barbería |

### Llaves únicas y foráneas

PostgreSQL revisa las llaves únicas, las restricciones de exclusión y las llaves foráneas sin aplicar las políticas. Por eso todas incluyen `barbershop_id`. Una llave única sin la barbería rechazaría un nombre que ya usa otra barbería y revelaría que existe; una llave foránea sin la barbería permitiría que una reserva apunte al servicio de otra barbería.

### Cómo agregar una tabla de una barbería

1. La tabla lleva `barbershop_id`, y sus llaves únicas y foráneas lo incluyen.
2. Una migración habilita y fuerza la seguridad a nivel de fila y crea la política `<tabla>_barbershop_isolation` con la misma expresión en `USING` y `WITH CHECK`; su `down()` deshace los tres pasos. El SQL se repite en cada migración, sin ayudante: una migración no debe cambiar si cambia el código.
3. Las rutas que la usan pasan por el middleware `barbershop`, y su fábrica fija la barbería de su fila.
4. Su recurso se registra en `tests/Support/Tenancy/TenantResources.php`: la tabla, cómo crear una fila para una barbería, la ruta base con `{barberia}` y las operaciones que ofrece (listar, ver, modificar, borrar). Con eso la suite `tests/Feature/Tenancy` lo prueba contra una segunda barbería. Una ruta pública con `{barberia}` se declara en `PUBLIC_ROUTES` de ese archivo y usa `barbershop:public`. Su caso se agrega también a la matriz de permisos.

La prueba `tests/Feature/Tenancy/BarbershopPolicyCatalogTest.php` revisa el catálogo de PostgreSQL y falla si una tabla con `barbershop_id`, salvo las excepciones listadas, no tiene la seguridad habilitada, forzada y exactamente la política `<tabla>_barbershop_isolation` con la expresión de arriba. Una segunda política también falla, porque las políticas permisivas se suman. La lista de excepciones vive en `tests/Support/Shared/BarbershopIsolation.php` y debe coincidir con la tabla de arriba.

`tests/Feature/Tenancy/TenantResourceCoverageTest.php` falla si una tabla con `barbershop_id` (salvo las excepciones) no figura en el registro de recursos, o si una ruta con `{barberia}` no pasa por el middleware `barbershop` o no pertenece a un recurso del registro ni está en `PUBLIC_ROUTES`. `tests/Feature/Tenancy/TenantResourceIsolationTest.php` recorre el registro con Pura Navaja y Fade Lab (`DemoBarbershopSeeder`): la persona dueña de Pura Navaja que pone en la ruta la barbería o la fila de Fade Lab recibe el mismo 404 que ante un recurso inexistente, ningún listado trae filas ajenas, la fila ajena no cambia y una consulta sin filtro no devuelve filas de la otra barbería. La lógica vive en `tests/Support/Tenancy/TenancyAudit.php`, que devuelve la lista de violaciones, y `tests/Feature/Tenancy/TenancyAuditTest.php` demuestra sobre la tabla `tenancy_probe` que esa lista no queda vacía ante una fuga deliberada (una ruta sin el middleware, una ruta que confía en la barbería de la URL o una tabla sin su política).

`tests/Feature/Tenancy/RowLevelSecurityTest.php` prueba el comportamiento sobre una tabla temporal, `tenancy_probe`, que crea con la conexión administrativa y descarta al terminar la prueba: con el contexto de una barbería solo se ven sus filas, sin contexto no se ve ninguna, un `INSERT` o un `UPDATE` hacia otra barbería falla con el SQLSTATE `42501`, una consulta que olvida el filtro no devuelve filas ajenas y el rol dueño queda limitado igual.

### Archivos de una barbería

Los archivos subidos (logo, fotos) se guardan separados por barbería (MT-05). El puerto `BarbershopFileStorage` deriva toda ruta del contexto (`barbershops/{barbershop_id}/...`), le pone un nombre generado por el servidor y rechaza con 404 cualquier ruta que salga de la carpeta de la barbería en curso (`..`, otra barbería, rutas absolutas, `\`, bytes nulos). El disco es el de `filesystems.media` (`public` por defecto). Servir el archivo por HTTP es del #46.

## Reparto de responsabilidades

El dominio, en PHP, decide las reglas del negocio. PostgreSQL garantiza la integridad de los datos, el aislamiento entre barberías, la exclusión de traslapes y las consultas por conjuntos. Ningún caso de uso se duplica en PL/pgSQL.

Todo objeto de la base se usa desde `Infrastructure/Persistence`, detrás de un puerto. El dominio y los casos de uso no saben que existen.

## Pendientes

1. **Procesos sin sesión.** Los webhooks de las pasarelas, las tareas en cola (expiración de reservas temporales, recordatorios, ofertas) y las tareas programadas no tienen una persona autenticada ni una barbería en la ruta. Hay que decidir cómo fijan la barbería (por ejemplo, tomándola del pago o de la reserva que procesan) y si recorrer todas las barberías exige un tercer rol de base de datos, de mantenimiento. El proyecto de referencia difiere esta decisión hasta que exista la primera tarea de ese tipo; en BarberHub llega con M08.