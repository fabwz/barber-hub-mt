# ADR 0005: Multi-inquilino por un puerto y por seguridad a nivel de fila

Estado: aceptada; implementada en #38.
Fecha: 16 de octubre de 2026. Implementación: 19 de octubre de 2026.

## Contexto

Varias barberías comparten la misma instalación y la misma base. Un olvido en una consulta no puede exponer los datos de otra barbería (MT-02, MT-06).

A diferencia del proyecto de referencia, donde cada persona del personal pertenece a un solo negocio y el negocio sale de la persona autenticada, en BarberHub una persona puede tener roles en varias barberías: dueña en una, clienta en otra (UP-07). La barbería no se puede deducir de la persona.

## Decisión

Hay dos barreras.

La primera está en el código. En las rutas, `{barberia}` es el UUID v7 de la barbería (RD-18) y se restringe con `whereUuid('barberia')`. El middleware `barbershop` (`ScopeRequestToBarbershop`) verifica que la sesión tenga una membresía activa en esa barbería y solo entonces fija el contexto a través del puerto `BarbershopContext`. Cada repositorio filtra por ese `BarbershopId`. Sin rol en la barbería, o con un recurso de otra, la respuesta es 404 con el mismo cuerpo que un recurso inexistente (UP-06). La barbería nunca se toma del cuerpo, de la consulta ni de un encabezado.

La segunda está en PostgreSQL: políticas de seguridad a nivel de fila sobre toda tabla con `barbershop_id`. Para que sirva, la aplicación se conecta con un rol que no es dueño de las tablas (ADR 0006), y las tablas fuerzan la política para que tampoco escape el dueño.

### Cómo funciona

- **Puerto.** `BarbershopContext` (`Shared/Domain/Contracts`) responde `current(): BarbershopId`. Su adaptador, `RequestBarbershopContext`, lee el atributo `barbershop_id` de la solicitud en curso, que solo el middleware escribe. Sin ese atributo falla cerrado: lanza `AuthenticationException` y no hay ninguna barbería por omisión.
- **Membresía.** Para no acoplar `Shared` a `Identity`, el middleware pregunta el rol por el puerto `BarbershopRoles` (`roleOf(UserId, BarbershopId): ?string`). Su adaptador, `EloquentBarbershopRoles`, vive en `Identity/Memberships` y solo cuenta las membresías activas. La consulta ocurre **antes** de fijar el contexto: `memberships` no tiene política de fila, justamente para poder leerse en este punto.
- **Dos modos.** `barbershop` (por defecto, rutas con `{barberia}` y sesión): sin membresía activa, o con una barbería inexistente, responde 404 `no-encontrado`; con ella guarda `barbershop_id` y `barbershop_role` en los atributos de la solicitud y ejecuta la petición dentro de la transacción de la barbería. `barbershop:public` (rutas públicas con `{barberia}` y sin sesión): fija la barbería de la ruta sin verificar membresía y deja el rol vacío. Un modo desconocido lanza `InvalidArgumentException`. Al terminar, `finally` quita los atributos.
- **Transacción.** `BarbershopTransaction` abre `DB::transaction` y ejecuta `set_config('app.barbershop_id', ?, true)`; con `null` fija el texto vacío y no se alcanza ninguna fila. Como la variable es local a la transacción, desaparece al confirmar o deshacer y una conexión reutilizada no hereda la barbería anterior. No sustituye a `DatabaseTransactionRunner`: las transacciones de los casos de uso, anidadas dentro de la del middleware, son puntos de guardado y la barbería sigue siendo la de la petición.
- **Política por tabla.** `<tabla>_barbershop_isolation`, habilitada y forzada, con la misma expresión en `USING` y en `WITH CHECK`. Cada tabla recibe la suya en la migración que la crea; el #38 entrega el mecanismo y las pruebas (una tabla temporal de prueba y una prueba del catálogo de PostgreSQL que falla si una tabla con `barbershop_id` no la tiene).
- **Archivos (MT-05).** `BarbershopFileStorage` (`Shared/Domain/Contracts`) guarda, borra y da la dirección de archivos. Su adaptador deriva toda ruta de la barbería del contexto (`barbershops/{id}/...`) con un nombre generado por el servidor, y rechaza con `FileOutsideBarbershopException` (404) cualquier ruta que salga de esa carpeta. Los endpoints de subida llegan con el #46.

### Lo que queda para otros issues

Las tres excepciones de RD-18 (operaciones de cliente que crean la primera membresía, propuesta de IA y lecturas de datos propios) no son modos todavía. Son ganchos para el #56 y el #57, que agregarán su modo al mismo middleware. Los webhooks y las tareas en cola siguen pendientes (M08).

## Alternativas descartadas

- **Deducir la barbería de la persona autenticada**, como en la referencia. No funciona cuando una persona tiene roles en varias barberías.
- **Fijar la barbería en el token.** Obligaría a emitir un token por barbería y a volver a ingresar para cambiar de una a otra.
- **Un encabezado de barbería activa.** Es un dato que el cliente controla y que no identifica el recurso; RD-18 lo descarta.
- **Una base o un esquema por barbería.** Resuelve un problema de escala que el proyecto no tiene y complica las migraciones.
- **Solo un filtro global de Eloquent.** Es una sola barrera y se desactiva con una llamada.
- **Un ayudante reutilizable para crear la política desde las migraciones.** Una migración debe ser una instantánea que no cambie si cambia el código. Se repite el SQL y la prueba del catálogo garantiza que todas usen la misma expresión.
- **Guardar la barbería en un objeto compartido del contenedor.** Habría dos fuentes de verdad (el objeto y la solicitud). Los atributos de la solicitud no sobreviven a ella.

## Consecuencias

- Cambiar de dónde sale la barbería afecta a un solo adaptador.
- Toda llave única y foránea incluye `barbershop_id`.
- Las migraciones corren con un rol y la aplicación con otro. Como el dueño también está sujeto a las políticas, los sembradores y las fábricas de prueba fijan la barbería antes de insertar.
- `users`, `barbershops` y `memberships` no tienen política de fila porque se leen antes de conocer la barbería; se protegen por código y por pruebas. En `memberships` toda consulta filtra por `user_id`.
- Un recurso de otra barbería debe responder con el mismo cuerpo genérico `no-encontrado` que el middleware (UP-06); las pruebas por recurso con dos barberías llegan con el #123.
- Los webhooks y las tareas en cola no tienen sesión: cómo fijan la barbería queda pendiente (ver [database.md](../database.md)).
