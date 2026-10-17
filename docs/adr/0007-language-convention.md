# ADR 0007: Convención de idioma

Estado: aceptada.
Fecha: 16 de octubre de 2026.

## Contexto

El código se escribe en inglés, el sistema lo usan personas que leen español y el contrato de la API lo consume un cliente web. Sin una regla, los dos idiomas se mezclan y cada persona nombra las cosas a su manera (RD-07).

## Decisión

Código, tablas, columnas y commits, en inglés. Rutas de la API, atributos JSON, valores de estados y tipos de error, y mensajes al usuario, en español. La traducción entre ambos idiomas ocurre en un solo lugar: los Form Requests de entrada y los API Resources de salida. [ubiquitous-language.md](../ubiquitous-language.md) relaciona cada término del negocio con su nombre en cada lugar.

## Alternativas descartadas

- **Todo en inglés, también la API.** El contrato dejaría de usar el vocabulario del negocio.
- **Todo en español, también el código.** Mezcla los identificadores con las palabras reservadas del lenguaje y de las bibliotecas.

## Consecuencias

- Los tipos de TypeScript generados desde OpenAPI heredan los nombres en español.
- Cada término nuevo del negocio se agrega al glosario antes de usarse.
