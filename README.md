# BarberHub

Plataforma web multi-inquilino de reservas y cobro para barberías. Proyecto integrador del curso ISW-621 Programación en Ambiente Web II (UTN, Sede San Carlos).

## Estructura prevista

- `barberhub-api`: API REST en Laravel 13 y PHP 8.4, con PostgreSQL 18.
- `barberhub-web`: cliente web en Vue con TypeScript.

## Documentación: `context/`, `docs/` y `.claude/`

- **`context/`:** qué debe hacer el sistema (requisitos y reglas de negocio). Es la fuente de verdad funcional: los issues la citan por identificador (por ejemplo RF-10 o MT-03) y solo cambia por decisión del dueño del proyecto.
- **`docs/`:** cómo está construido el sistema (arquitectura, base de datos, modelo de datos, lenguaje ubicuo y decisiones). Evoluciona con el código y se completa en M02 (#21 a #26).
- **`.claude/`:** instrucciones y configuración del asistente de código.

**Mantenimiento:** si algo cambia, se corrige primero el archivo y después lo que dependa de él. Los identificadores no se reutilizan.

## Flujo de trabajo

- **Ramas:** una por issue, con el formato `<número>-<título-en-minúsculas-con-guiones>`. Ejemplo: `15-inicializar-el-repositorio-con-barberhub-api-barberhub-web-y-el-flujo-de-trabajo`.
- **Commits:** convencionales y en inglés, `<type>(<scope>): <description>`, con el pie `Refs: #n`. Ejemplo: `docs(readme): document the workflow` con el pie `Refs: #15`.
- **Pull requests:** uno por issue, con el título del issue y `Closes #n` en la descripción. Se integra con merge commit.
- **`main`:** está protegida; no admite cambios directos y exige pull request.

## Estado

En construcción. La instalación y la declaración de uso de IA se documentan a medida que avanza el proyecto.
