# ADR 0006: PostgreSQL con dos bases de datos y dos roles

Estado: aceptada.
Fecha: 16 de octubre de 2026.

## Contexto

La seguridad a nivel de fila (ADR 0005) solo protege si la aplicación no es dueña de las tablas ni superusuaria, porque ambos saltan las políticas. Además, la suite de pruebas borra y recrea datos, y no debe poder tocar los de desarrollo (RD-02).

## Decisión

PostgreSQL 18 es el único motor. Hay dos bases: `barberhub`, para desarrollo y despliegue, y `barberhub_testing`, solo para las pruebas. Hay dos roles: el dueño de las tablas, que ejecuta las migraciones por una conexión administrativa, y el de la aplicación, que atiende las peticiones sin ser dueño, superusuario ni tener `BYPASSRLS`. Un script versionado (`database/roles.sql`) crea ambos, con las contraseñas recibidas por variables; una migración inicial otorga al rol de la aplicación los permisos sobre las tablas futuras.

## Alternativas descartadas

- **Un solo rol para todo.** Es más simple, pero deja sin efecto la seguridad a nivel de fila.
- **Una sola base compartida entre desarrollo y pruebas.** Un `RefreshDatabase` borraría los datos de desarrollo.
- **SQLite para las pruebas.** Es más rápido, pero no entiende las restricciones que el proyecto usa.

## Consecuencias

- Las pruebas corren contra PostgreSQL real, con el rol de la aplicación, y la CI ejecuta el mismo script.
- Hay que gestionar dos conexiones de Laravel y dos contraseñas fuera del repositorio.
- Un tercer rol, de mantenimiento, se agrega si una tarea debe recorrer todas las barberías (pendiente).
