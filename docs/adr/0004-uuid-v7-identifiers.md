# ADR 0004: Identificadores UUID versión 7

Estado: aceptada.
Fecha: 16 de octubre de 2026.

## Contexto

Un identificador autoincremental lo asigna la base: el agregado no tendría identidad hasta guardarse. Además, en una API pública `/reservas/41` revela cuántos registros hay y permite recorrerlos. En BarberHub el identificador de la barbería viaja en la ruta (RD-18), así que no puede ser enumerable.

## Decisión

Toda llave primaria es un UUID versión 7. La aplicación lo genera antes de guardar, a través del repositorio. La columna tiene `DEFAULT uuidv7()`, función nativa de PostgreSQL 18, como respaldo. Cada identificador tiene su propio tipo en el dominio (`BarbershopId`, `ReservationId`). La excepción es `personal_access_tokens.id`, que pertenece a Sanctum.

## Alternativas descartadas

- **Enteros autoincrementales.** Enumerables y dependientes de la base.
- **UUID versión 4.** Aleatorio: fragmenta el índice primario al insertar.
- **ULID.** Equivalente en orden, pero no es un tipo nativo de PostgreSQL.

## Consecuencias

- Los identificadores están ordenados por tiempo y no revelan volumen.
- El analizador estático rechaza pasar el identificador de una reserva donde se espera el de una barbería.
- PostgreSQL 18 es obligatorio.
