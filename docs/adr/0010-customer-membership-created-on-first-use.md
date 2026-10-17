# ADR 0010: La membresía de cliente se crea al primer uso

Estado: aceptada.
Fecha: 16 de octubre de 2026.

## Contexto

Una cuenta es global y el rol se asigna por barbería (UP-01). Un cliente nuevo llega por el enlace público de una barbería donde todavía no tiene rol, y con la regla de RD-18 una persona sin rol recibe 404. Hacía falta definir cuándo nace su rol de cliente en esa barbería.

## Decisión

La membresía de cliente se crea al primer uso de una operación de cliente en la barbería: crear una reserva, comprar un bono o anotarse en una fila de espera. La crea el sistema en la misma transacción que la operación, así que si la operación falla no queda ninguna membresía (UP-09). Quien ya tiene otro rol en esa barbería (dueño o colaborador) no puede crearla: la operación responde 403 `prohibido`, porque una persona tiene un único rol por barbería (UP-07). La propuesta de IA no crea la membresía, y las lecturas de datos propios sin rol devuelven una lista vacía (RD-18).

## Alternativas descartadas

- **Unirse explícitamente a la barbería con un endpoint propio.** Es más claro, pero agrega un paso y un caso de uso, y la reserva debe completarse en 5 pasos o menos (RNF-21).
- **Crear la membresía al registrarse la cuenta.** Una cuenta es global y no pertenece a ninguna barbería todavía; llenaría de membresías vacías a quien nunca reserva.
- **Permitir que un dueño o colaborador reserve como cliente sin segundo rol.** Crea un rol oculto y multiplica los casos: la lista de reservas, a quién pertenece un bono, los avisos del cliente y la matriz de permisos.

## Consecuencias

- Las rutas de cliente que crean membresía son una excepción explícita a la regla de 404 de RD-18.
- Un dueño o colaborador que quiera reservar como cliente en su propia barbería usa otra cuenta.
- La lista de barberías de una persona (UP-08) solo incluye las membresías que existen, así que llegar por un enlace público no agrega una barbería a la lista.
