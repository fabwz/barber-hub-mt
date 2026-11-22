# Rendimiento de la disponibilidad y de la creación de reservas

Medición del issue #128 para RNF-01 (disponibilidad de un día, p95 ≤ 1 s) y RNF-02 (crear una reserva, p95 ≤ 2 s, sin contar la pasarela).

**Resultado: los dos umbrales se cumplen con un margen de más de 20 veces.**

## Cómo se midió

- **Fecha:** 21 de noviembre de 2026.
- **Commit base:** `6d51277` (rama `128-medir-el-rendimiento-de-la-disponibilidad-y-de-la-creacion-de-reservas`), con el script de medición todavía sin confirmar en ese momento.
- **Comando exacto** (desde `barberhub-api`):

  ```bash
  php performance/measure.php run --json=../docs/performance/2026-11-21-rendimiento.json
  ```

  Equivale a `--iterations=200 --bookings=100 --warmup=20`. Los datos crudos están en [2026-11-21-rendimiento.json](2026-11-21-rendimiento.json).
- **Entorno:** Windows 11 (NT 10.0, AMD64), Intel Core de la familia 6 modelo 142 con 8 procesadores lógicos, 15,8 GB de RAM, PHP 8.4.23 sin OPcache en la CLI, Laravel 13.37.0 y PostgreSQL 18.4 en la misma máquina (`barberhub_testing`).
- **Qué mide:** cada petición entra por el núcleo HTTP de la aplicación dentro del mismo proceso: enrutamiento, middleware, validación, casos de uso y consultas reales a PostgreSQL con el rol de la aplicación y la seguridad a nivel de fila. **No incluye** la red ni el servidor web (PHP-FPM, Octane), ni el tiempo posterior a la respuesta. El tiempo es el del núcleo, medido con `hrtime`.
- **Sin pasarela:** la barbería de la medición no cobra (`payment_mode = none`), así que reservar confirma al instante y no se llama a ninguna pasarela.
- **Calentamiento:** 20 peticiones de cada escenario que no se cuentan. Percentiles por rango más cercano.
- **Reloj fijo:** viernes 1 de marzo de 2030, 8:00, para que los 30 días de datos sean siempre los mismos.

## Datos

Pura Navaja (ficticia): 4 colaboradores (Mauricio, Daniela, Kevin y Esteban, con los horarios, pausas y servicios del sembrador de demostración), 7 servicios, 150 clientes y 471 reservas repartidas en los 30 días siguientes al reloj fijo (≈ 30 % de ocupación, con un 10 % canceladas y su historial de estados). El generador usa una semilla fija, por lo que produce siempre las mismas reservas.

## Resultados

Milisegundos. «SQL» es el promedio de consultas por petición.

| Escenario | Muestras | p50 | p95 | p99 | máx. | SQL | Umbral p95 | Cumple |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :---: |
| `GET /disponibilidad` de un día, todos los barberos del servicio | 200 | 15,1 | 31,8 | 34,3 | 35,3 | 17,0 | 1 000 | Sí |
| `GET /disponibilidad` de un día, un barbero | 200 | 14,2 | 35,7 | 41,5 | 52,6 | 19,8 | 1 000 | Sí |
| `GET /disponibilidad` de un día, «cualquiera» (extra, #54) | 200 | 20,6 | 46,2 | 51,2 | 54,9 | 23,0 | 1 000 | Sí |
| `POST /reservas` con un barbero, sin cobro | 100 | 31,1 | 33,7 | 36,0 | 39,8 | 29,0 | 2 000 | Sí |
| `POST /reservas` con «cualquiera», sin cobro (extra) | 100 | 43,3 | 48,8 | 50,0 | 53,2 | 42,0 | 2 000 | Sí |

Ninguna petición medida falló. RNF-01 se evalúa con el primer escenario (la consulta más amplia del día); los otros dos de disponibilidad son variantes.

## Decisiones y límites

- **Cada reserva es la primera de su cliente.** Cada petición usa una cuenta nueva, así que también crea el rol de cliente (UP-09): es el caso más costoso.
- **Los espacios se piden antes de reservar.** Antes de cada reserva el script consulta un espacio libre (sin cronometrar), como lo hace un cliente, y luego reserva ese espacio (cronometrado). Cada escenario de reserva usa 100 muestras porque cada una consume un espacio y el calendario es finito.
- **Calendario con huecos.** Con más ocupación (45 % y 70 % probadas al preparar el script) los espacios de 45 y 60 minutos se agotaban antes de completar las muestras; 30 % deja huecos suficientes.
- **Límite de la medición.** Sin red ni servidor web, y con OPcache apagado en la CLI, la cifra es la del núcleo de la aplicación más la base de datos. Una medición con el servidor real (por ejemplo en CI de despliegue) puede sumar decenas de milisegundos; con el margen actual no cambiaría la conclusión.
- **Aislamiento.** El script solo acepta `barberhub_testing` (o `barberhub_performance` con la variable `PERF_DATABASE`) y se niega a arrancar contra otra base o en producción. Siembra y limpia con la conexión administrativa; sus filas tienen identificadores fijos y correos `@perf.barberhub.test`. No corre en la CI. Las pruebas (`php artisan test`) recrean `barberhub_testing`, por lo que no deben correr a la vez.

## Cómo repetirla

```bash
cd barberhub-api
php performance/measure.php seed    # solo siembra (idempotente: borra y vuelve a crear)
php performance/measure.php run     # siembra, calienta, mide y limpia (--keep conserva los datos)
php performance/measure.php clean   # elimina todo lo que creó el script
```
