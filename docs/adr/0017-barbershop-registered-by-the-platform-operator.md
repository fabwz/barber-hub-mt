# ADR 0017: La barbería la registra el operador de plataforma, y una cuenta de operador no puede tener rol

Estado: aceptada.
Fecha: 1 de diciembre de 2026.

## Contexto

PL-02 dice que solo el operador de plataforma da de alta una barbería y a su dueño. El alta anterior (`POST /api/v1/barberias`) estaba abierta a cualquier cuenta y la dejaba como dueña. El ADR 0014 dejó pendiente que una cuenta de operador no recibiera un rol por otra vía (el alta o una invitación aceptada) y que se cerrara con una restricción en la base.

## Decisión

**Una sola ruta, la del operador.** `POST /api/v1/plataforma/barberias`, con `auth:sanctum` y `platform.operator`, sin `{barberia}` (la barbería no existe todavía). La ruta abierta desaparece: no se conserva con otro permiso, porque dos rutas para lo mismo dejarían la regla en un descuido. El cliente web no la consumía.

**Se reutiliza `RegisterBarbershop`.** Gana el dueño como dato (nombre, correo y teléfono) y el operador como autor. Todo ocurre en la misma transacción que ya existía: barbería, enlace, cuenta, rol, primera cuota y entrada del registro técnico.

**La cuenta del dueño sale de un puerto, no de otro dominio.** `OwnerAccounts` (de `Barbershops`) lo atiende `ProvideOwnerAccount` (de `Users`): un correo con cuenta no cambia en nada; uno sin cuenta crea una con contraseña temporal generada (`TemporaryPasswordGenerator`, la misma regla de SE-07 que usa el comando del operador) y `must_change_password`. La contraseña vuelve **solo** en la respuesta (`Cache-Control: no-store`) y no se escribe en la base (solo su hash), ni en el registro técnico, ni en los logs. La respuesta distingue los dos casos con `dueno.cuenta_creada`.

**Una cuenta de operador no recibe rol, en dos capas.**
- En los casos de uso: `AssignBarbershopOwner` y `GrantCollaboratorMembership` consultan el puerto `PlatformOperators` y lanzan `OperatorCannotHoldRoleException`, que responde 409 `conflicto`. Se eligió ese tipo, que ya existe, porque el rol que se pide choca con el estado de la cuenta, igual que cuando ya tiene otro rol en la barbería (UP-07); no se creó un tipo nuevo ni se usó 403, que en esta API significa «tu rol no alcanza». Aceptar una invitación al correo de un operador falla antes de gastar el enlace.
- En la base: el disparador `trg_memberships_exclude_platform_operators` rechaza cualquier fila de `memberships` de una cuenta marcada (`CHECK`, `23514`), sea cual sea el rol o la ruta. El repositorio traduce ese error a la misma excepción.

**El registro técnico.** `barbershop.registered`, con el operador como autor y la barbería nueva como sujeto. Guarda el enlace, el id del dueño y si su cuenta se creó; nunca el correo, el teléfono ni la contraseña. Como la ruta no trae `{barberia}`, la entrada se escribe con `BarbershopScope`, que fija la barbería recién creada para la política de fila de `audit_log`.

## Alternativas descartadas

- **Dejar la ruta abierta con una comprobación de operador en el caso de uso.** El permiso se decide en la ruta y en la matriz (`AccessMatrix`), donde una prueba lo vigila.
- **Un disparador también en `users`** (impedir marcar como operador a una cuenta con membresías). El comando del operador ya rechaza todo correo con cuenta, así que ningún camino marca una cuenta existente; se añade si algún día se permite.
- **Que el operador escriba la contraseña temporal.** Un valor generado cumple siempre la política y no pasa por el teclado de nadie.
- **Opcionales de RF-01 (logo, banner, dirección, redes) en el alta.** El alta anterior no los recibía y el dueño ya los completa en «negocio» e «imágenes»; agregarlos al alta sería un requisito nuevo.

## Consecuencias

- Una cuenta que ya es dueña de otra barbería puede ser dueña de otra más: el alta no cambia su cuenta.
- El operador entrega la contraseña temporal por un canal seguro: la API no la vuelve a mostrar. Si se pierde, la persona usa «Olvidé mi contraseña» (RF-41).
- La pantalla del operador (formulario y la pantalla de la contraseña mostrada una vez) es del issue web del operador.
