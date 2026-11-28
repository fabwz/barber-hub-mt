# ADR 0016: El bloqueo por alquiler es una columna de la barbería y el registro técnico admite al sistema como autor

Estado: aceptada.
Fecha: 30 de noviembre de 2026.

## Contexto

RF-58 pide bloquear la barbería el día siguiente al vencimiento de una cuota sin pagar y desbloquearla cuando no le queden meses pendientes, dejando ambos cambios en el registro técnico (PL-12, SE-04). La tarea corre sin sesión, así que no hay una persona a la que atribuir el cambio, y `audit_log.actor_user_id` era obligatorio. El #296 solo marca y desmarca el estado; lo que una barbería bloqueada puede hacer es del RF-59.

## Decisión

**El estado es `barbershops.blocked_at` (`timestamptz`, nulo = no bloqueada).** Una sola columna, sin tabla de historial propia: el historial es el registro técnico. No se guarda ningún contador ni una copia de los meses pendientes; el bloqueo los obtiene de `CountPendingRentMonths` (ADR 0015). `barbershops` no tiene política de fila, así que la columna no necesita una.

**Bloquear y desbloquear son `UPDATE` condicionales sobre la fila ya tomada con `FOR UPDATE`.** Bloquear una barbería bloqueada no cambia ninguna fila y no escribe una segunda entrada: la idempotencia la decide la base. El desbloqueo cuenta los meses pendientes él mismo con el candado tomado, de modo que el pago del alquiler (RF-59) y la tarea diaria se turnan y nunca queda bloqueada una barbería sin deuda.

**El desbloqueo es un caso de uso sin transacción ni ruta**, para que el pago lo llame dentro de la transacción que liquida las cuotas (PL-11): si el pago se revierte, el desbloqueo también.

**Autor «sistema» = `audit_log.actor_user_id` nulo.** Se eligió sobre crear una cuenta «sistema» en `users` (una persona falsa que podría aparecer en listados, correos o ingreso) y sigue el criterio que ya usa `reservation_status_history.changed_by_user_id`. `AuditEntry` acepta un autor nulo (`AuditEntry::bySystem()`); el disparador de solo-anexar y los permisos no cambian porque quitar `NOT NULL` no actualiza ninguna fila. Dos acciones nuevas, `barbershop.blocked` y `barbershop.unblocked`, con el campo permitido `blocked_at`.

**Mismo mecanismo sin sesión que las cuotas.** Una tarea diaria a las 00:10 de Costa Rica (después de la generación de las 00:05) lista las barberías con el rol de la aplicación y procesa cada una en `BarbershopProcessScope`. Sin tercer rol de base de datos.

## Consecuencias

- La migración de `audit_log` es reversible solo mientras no existan entradas del sistema: el registro solo crece y no se pueden borrar ni atribuir a otra persona, así que `down()` falla en lugar de perderlas.
- Quien lea `audit_log` (hoy nadie) debe tratar un autor nulo como «sistema».
- El listado del operador (RF-56) y la respuesta `barberia-bloqueada` (RF-59) leerán `blocked_at`; ninguno se implementó aquí.
- En producción la tarea solo corre cuando exista el planificador (#117).
