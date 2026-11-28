# ADR 0014: El operador de plataforma es una marca de la cuenta, creada solo por comando

Estado: aceptada.
Fecha: 29 de noviembre de 2026.

## Contexto

RF-55 y PL-01 piden que el operador de plataforma sea una condición de la cuenta, sin membresía ni rol de barbería, que no se asigne por la API y que se cree por semilla o comando. Sus rutas no llevan `{barberia}` (RD-18) y, en una ruta de barbería, recibe 404 como quien no tiene rol (UP-06). El panel de monitoreo (ADR 0013) quedó con autenticación básica a la espera de este issue (#245).

## Decisión

**Una columna en `users`, no una tabla.** `users.is_platform_operator boolean NOT NULL DEFAULT false`. La cuenta ya es global y sin seguridad a nivel de fila, así que la marca no necesita política ni llave de barbería. Una tabla propia o una fila en `memberships` repetiría lo que PL-01 prohíbe (una membresía) o agregaría un join sin ganar nada.

**Solo un comando la escribe.** `php artisan platform:create-operator {email} {name}` crea la cuenta marcada, con una contraseña temporal pedida o generada que se muestra una sola vez y no se guarda en ningún registro. Corre en producción, a diferencia del sembrador de demostración. Rechaza un correo que ya tiene cuenta: convertir una cuenta existente podría dejar a un operador con una membresía, y la regla PL-01 dice que no la tiene. Ninguna petición HTTP ni formulario recibe la marca: el modelo la admite en la asignación masiva solo porque el repositorio guarda la entidad, que sale de `User::createPlatformOperator()` o de la base.

**Dos barreras, una por sentido.**
- Hacia la plataforma: `platform.operator` (`RequirePlatformOperator`) va después de `auth:sanctum`, consulta el puerto `PlatformOperators` en cada petición (el token no lleva permisos) y responde 403 `prohibido` a toda otra cuenta.
- Hacia las barberías: `ScopeRequestToBarbershop` ya responde 404 a quien no tiene rol; en el modo de cliente de primer uso, donde una persona sin rol pasa y se le crea la membresía, el operador recibe 404 para que nunca se le cree.

**La matriz lo incluye como actor.** `AccessMatrix::ACTORS` suma `platform_operator`; el perfil `OPERATOR_ONLY` y la regla `RouteRule::operator()` describen las rutas de plataforma, y la prueba guardiana exige `auth:sanctum` más `RequirePlatformOperator`, sin barbería ni rol, bajo `plataforma`. La ruta mínima es `GET /api/v1/plataforma/cuenta` (solo lectura).

## Alternativas descartadas

- **Tabla `platform_operators`.** Más piezas para un dato de una sola columna.
- **Rol `platform_operator` en `memberships`.** Contradice PL-01 y la llave `(user_id, barbershop_id)`.
- **Una ability del token de Sanctum.** El token no lleva permisos (UP-07); quitar la marca no revocaría los tokens ya emitidos.
- **Marcar una cuenta existente.** Se puede añadir después con una comprobación de que no tiene membresías; hoy es más sencillo y seguro crear solo cuentas nuevas.

## Consecuencias

- Nada impide en la base que una cuenta operadora reciba una membresía por otra vía (por ejemplo, el alta actual de barbería o la aceptación de una invitación a su correo): eso pertenece al alta de barbería por el operador (RF-01, PL-02) y se cierra allí, con una restricción en la base si se decide.
- El panel de monitoreo sigue con su autenticación básica; pasarlo a la comprobación del operador es otro cambio.
