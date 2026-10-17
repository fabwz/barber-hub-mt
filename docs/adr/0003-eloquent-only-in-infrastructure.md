# ADR 0003: Eloquent solo en la infraestructura

Estado: aceptada.
Fecha: 16 de octubre de 2026.

## Contexto

Un modelo Eloquent puede guardarse, borrarse y consultarse desde cualquier parte. Si fuera la entidad del dominio, cualquier `update()` podría saltarse las reglas del negocio, por ejemplo cambiar el estado de una reserva sin pasar por sus transiciones válidas.

## Decisión

Las entidades del dominio son clases PHP sin herencia del framework. Los modelos Eloquent llevan el sufijo `Model`, viven en `Infrastructure/Persistence/Models` y nunca salen de la capa de persistencia. El repositorio traduce entre ambos. Las migraciones, las fábricas y los sembradores se quedan en `database/`.

## Alternativas descartadas

- **Usar el modelo Eloquent como entidad.** Menos código, pero el dominio queda atado a la base y al framework.
- **Otro ORM con mapeo de datos (Doctrine).** Resuelve el mapeo, pero abandona las herramientas que Laravel ya integra.

## Consecuencias

- El mapeo entre fila y entidad se escribe a mano en cada repositorio.
- El dominio se prueba sin base de datos y el esquema puede cambiar sin tocar las reglas.
