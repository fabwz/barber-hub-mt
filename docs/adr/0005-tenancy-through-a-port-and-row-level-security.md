# ADR 0005: Multi-inquilino por un puerto y por seguridad a nivel de fila

Estado: aceptada; pendiente de implementar en #38.
Fecha: 16 de octubre de 2026.

## Contexto

Varias barberías comparten la misma instalación y la misma base. Un olvido en una consulta no puede exponer los datos de otra barbería (MT-02, MT-06).

A diferencia del proyecto de referencia, donde cada persona del personal pertenece a un solo negocio y el negocio sale de la persona autenticada, en BarberHub una persona puede tener roles en varias barberías: dueña en una, clienta en otra (UP-07). La barbería no se puede deducir de la persona.

## Decisión

Hay dos barreras.

La primera está en el código. En las rutas, `{barberia}` es el UUID v7 de la barbería (RD-18). El middleware `barbershop` verifica que la sesión tenga un rol en esa barbería y solo entonces fija el contexto a través del puerto `BarbershopContext`. Cada repositorio filtra por ese `BarbershopId`. Sin rol en la barbería, o con un recurso de otra, la respuesta es 404 con el mismo cuerpo que un recurso inexistente (UP-06). La barbería nunca se toma del cuerpo, de la consulta ni de un encabezado.

La segunda está en PostgreSQL: políticas de seguridad a nivel de fila sobre toda tabla con `barbershop_id`. Para que sirva, la aplicación se conecta con un rol que no es dueño de las tablas (ADR 0006).

La regla tiene tres excepciones (RD-18): las operaciones de cliente que crean la primera membresía, la propuesta de IA y las lecturas de datos propios.

## Alternativas descartadas

- **Deducir la barbería de la persona autenticada**, como en la referencia. No funciona cuando una persona tiene roles en varias barberías.
- **Fijar la barbería en el token.** Obligaría a emitir un token por barbería y a volver a ingresar para cambiar de una a otra.
- **Un encabezado de barbería activa.** Es un dato que el cliente controla y que no identifica el recurso; RD-18 lo descarta.
- **Una base o un esquema por barbería.** Resuelve un problema de escala que el proyecto no tiene y complica las migraciones.
- **Solo un filtro global de Eloquent.** Es una sola barrera y se desactiva con una llamada.

## Consecuencias

- Cambiar de dónde sale la barbería afecta a un solo adaptador.
- Toda llave única y foránea incluye `barbershop_id`.
- Las migraciones corren con un rol y la aplicación con otro.
- `users`, `barbershops` y `memberships` no tienen política de fila porque se leen antes de conocer la barbería; se protegen por código y por pruebas.
- Los webhooks y las tareas en cola no tienen sesión: cómo fijan la barbería queda pendiente (ver [database.md](../database.md)).
