# ADR 0013: El monitoreo es un panel propio de solo lectura, con dos tablas técnicas

Estado: aceptada.
Fecha: 25 de noviembre de 2026.

## Contexto

RNF-15 pide un panel de monitoreo con errores, tiempos de respuesta, estado de las colas y tareas fallidas del sistema desplegado, y RNF-09 pide que una tarea que agota sus 3 intentos quede visible en él (#118, #117). El issue agrega que el acceso esté protegido y que una falla de la ruta de salud quede visible. RNF-24 y SE-03 prohíben que el monitoreo guarde datos personales, secretos o detalles internos. La referencia del docente no tiene monitoreo, y el rol de operador de plataforma (#245, M19) todavía no existe.

## Decisión

**Un panel propio, de solo lectura, en HTML sin JavaScript.** `GET /monitoreo`, fuera de `/api/v1` (Scramble solo documenta ese prefijo, así que no entra en el contrato OpenAPI), en el módulo `src/Shared/Monitoring` con las tres capas: puertos de lectura y de escritura, un caso de uso por consulta y DTO sin `payload` ni `exception`.

**Dos tablas técnicas**, sin `barbershop_id` y sin seguridad a nivel de fila, porque no pertenecen a una barbería (como `jobs`, `cache` o `personal_access_tokens`): `http_stats` (un agregado por minuto, método, patrón de ruta, clase de estado y clase de excepción) y `health_checks` (un registro por minuto y resultado de la ruta de salud). Las migra el rol dueño; el rol de la aplicación recibe sus privilegios por los privilegios por defecto existentes.

**Lista cerrada de lo que se guarda:** patrón de la ruta (no la URL), método, clase de estado, duraciones, la clase de la excepción (nunca su mensaje ni su traza) y booleanos de salud. Nunca usuario, correo, teléfono, token, cuerpo, SQL ni carga de las tareas. De `failed_jobs` el panel lee solo el nombre de la tarea y la clase de la excepción, extraídos dentro de la consulta SQL: `payload` y `exception` no llegan a PHP.

**Escritura.** Un middleware global que termina después de la respuesta registra cada petición con un `UPSERT` por minuto. La ruta de salud deja como máximo una fila por minuto y resultado. Un fallo de la escritura se registra (solo la clase) y nunca cambia la respuesta. `MONITOR_ENABLED=false` (el valor por defecto y el de las pruebas) enlaza los puertos de escritura a adaptadores nulos.

**Retención.** 14 días (la ventana de RNF-04), con borrado oportunista: la escritura intenta, como máximo una vez por hora y con un candado de caché, borrar hasta 10 000 filas vencidas de cada tabla. Sin planificador. Cuando #117 traiga uno, basta programar el mismo caso de uso (`PurgeExpiredMonitoringData`) y dejar de llamarlo desde el middleware.

**Acceso.** Autenticación básica con `MONITOR_USER` y `MONITOR_PASSWORD_HASH` (bcrypt), comparación de tiempo constante y límite de 5 intentos fallidos por IP. Falla cerrada: con `MONITOR_ENABLED=false` o sin el usuario o el hash configurados, la ruta responde 404. Un rol de barbería no concede acceso, y el panel no usa Sanctum. En la matriz de permisos es una ruta de **plataforma**: ni pública, ni de una barbería. Cuando exista el operador (#245), se cambia el middleware por esa comprobación.

## Alternativas descartadas

- **Laravel Pulse.** Cubre errores, peticiones lentas (umbral de 1 s, no todos los tiempos) y el conteo de tareas por cola, pero no lista `failed_jobs` ni ve la ruta de salud, así que habría que escribir tarjetas Livewire propias para dos de los tres criterios. Agrega Livewire y rutas propias que clasificar, cuatro tablas de un paquete y, por defecto, resuelve usuarios con nombre y correo (y avatares de Gravatar) que habría que apagar para cumplir RNF-24. No se verificó su compatibilidad con Laravel 13. Queda como la opción si se prefiere menos código propio.
- **Telescope, Sentry o un servicio externo.** Guardan cuerpos, consultas o trazas (contra SE-03) o son un servicio de terceros que el proyecto no contrata.
- **Leer el log.** Frágil, sin tiempos y sin estructura.
- **Autenticación de Sanctum o compuerta por correos.** La página HTML no tiene cómo recibir el token (la conservación de la sesión espera al #75) y el operador no existe.
- **Autenticación del alojamiento.** Queda fuera del repositorio y no se puede probar.

## Consecuencias

- **Un `UPSERT` por petición**, después de la respuesta y con una escritura de unos pocos milisegundos. No se midió en este issue.
- **Lo que el panel no ve:** si el servidor entero cae no se registra nada. Solo un monitor externo que llame a `/salud` lo detecta, y el panel lo muestra como «sin chequeos recientes». Si la base cae, el panel tampoco funciona.
- **Los reintentos intermedios no están en la base:** solo existen en el log (`queue.job.attempt_failed`). El panel muestra lo que agotó sus intentos (`failed_jobs`) y una cola que envejece.
- **El panel no lanza procesos:** los workers y Reverb los pone #117 y #136. Sin worker, `jobs` crece y la tarea más vieja envejece.
- **Fuera de alcance:** el consumo de correo del día frente al plan de Resend, los reintentos intermedios y el monitor externo.
