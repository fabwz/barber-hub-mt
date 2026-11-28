# ADR 0015: El alquiler son cuotas mensuales que crea una tarea diaria, sin contador

Estado: aceptada.
Fecha: 29 de noviembre de 2026.

## Contexto

RF-57 pide que cada barbería tenga una cuota de alquiler por mes, con su vencimiento, y que los meses pendientes salgan de contar las cuotas vencidas sin pagar (PL-05). Una tarea diaria en hora de Costa Rica las crea sin sesión (#295). El monto (PD-09) y el cobro (RF-59) siguen pendientes.

## Decisión

**Una fila por mes, sin contador.** `rent_installments` guarda el monto con que nació y el día de vencimiento. Los meses pendientes se calculan (`CountPendingRentMonths`): cuotas `pending` cuyo `due_on` es anterior a hoy en Costa Rica. Un contador se desfasaría con cada pago, con cada caída de la tarea y con cada cambio de zona; contar no.

**El monto se copia al crear la cuota.** El monto vigente entra por el puerto `RentAmount`, hoy leído de `RENT_MONTHLY_AMOUNT` (valor de demostración provisional). Cambiarlo no toca cuotas ya generadas. Cuando el operador pueda editarlo basta otro adaptador del puerto.

**Cuándo se crea cada cuota.** La primera, al dar de alta la barbería y con vencimiento ese día; la siguiente, el día después de que vence la última. Se eligió la regla más simple que da una sola cuota por delante, y que dos ejecuciones el mismo día no cambien nada. Una barbería anterior al issue recibe solo su primera cuota, en su próximo aniversario, sin deuda hacia atrás.

**La idempotencia la decide la base de datos.** Llave única `(barbershop_id, due_on)` e inserción con `ON CONFLICT DO NOTHING`.

**Sin tercer rol de base de datos.** La decisión 20 pedía el rol de mantenimiento porque `barbershops` no tiene política de fila. Basta eso: la tarea lista las barberías con el rol de la aplicación y trabaja cada una dentro de `BarbershopProcessScope`, así que `rent_installments` conserva su política forzada. El rol de mantenimiento sigue sin decidirse para las lecturas entre barberías de tablas con política de fila (listado del operador, RF-56).

**Dónde vive.** Módulo `RentInstallments` del contexto `Catalog` (RD-05 cierra la lista de ocho contextos y la cuota cuelga de la barbería). El cobro (RF-59) lo tomará `Payments` por un puerto.

## Consecuencias

- La tarea declara su programación diaria en código; en producción solo corre con el planificador (#117). Hasta entonces se ejecuta a mano.
- Una barbería con una cuota que falla no detiene a las demás; el comando termina con error y la lista de las que fallaron.
- El listado del operador y el bloqueo reutilizan `CountPendingRentMonths`, con la barbería ya fijada.
- El bloqueo (#296) guarda su estado en `barbershops.blocked_at`, no en las cuotas; ver [ADR 0016](0016-rent-block-state-and-system-author.md).
