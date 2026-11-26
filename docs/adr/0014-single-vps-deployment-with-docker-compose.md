# ADR 0014: Despliegue en un VPS único con Docker Compose

Estado: aceptada.
Fecha: 26 de noviembre de 2026.

## Contexto

El #136 pide la API publicada de forma mínima, con la ruta de salud, PostgreSQL 18 con sus dos roles y un procedimiento manual documentado. Su nota deja pendiente el alojamiento, que debe admitir PostgreSQL, workers y WebSockets (PD-03). El Proyecto exige un sistema desplegado desde la semana 3, y la rúbrica de Operación pide colas con workers en producción, ruta de salud y panel de monitoreo. La ventana de 14 días de RNF-04 (#120) empieza el día del despliegue. El sistema no se publica como servicio comercial (RL-05).

## Decisión

**Alojamiento.** Un solo VPS con Docker Compose (`deploy/docker-compose.yml`). Seis servicios en una red privada: `db` (PostgreSQL 18), `app` (PHP-FPM), `worker-default`, `worker-realtime`, `reverb` y `proxy` (Caddy con los archivos estáticos de la web). Solo el proxy publica puertos (80 y 443).

**Una imagen de la API.** `deploy/api.Dockerfile` (PHP 8.4, `pdo_pgsql`, `intl`, `pcntl`, Composer sin dependencias de desarrollo) sirve a `app`, a los dos workers y a `reverb`; solo cambia el comando. Nada corre como root. El arranque guarda en caché la configuración, las rutas y los eventos con las variables de ese contenedor. PHP-FPM con `clear_env = no`, sin Octane ni dependencias nuevas.

**Un solo dominio.** El proxy reparte `/api/v1/*`, `/salud` y `/monitoreo` a PHP-FPM, `/app/*` a Reverb (`wss`) y todo lo demás a la web, con el respaldo de la SPA a `index.html`. El cliente ya llama a `/api/v1` por una ruta relativa, así que no hace falta CORS ni cambiar el cliente. La API de publicación de Reverb (`/apps/*`) no se expone: los workers publican por la red interna (`REVERB_HOST=reverb`, `http`, 8080).

**HTTPS.** Caddy obtiene y renueva el certificado de Let's Encrypt y redirige HTTP a HTTPS. El dominio es un subdominio gratuito (por ejemplo DuckDNS) que se escribe en la variable `DOMAIN`.

**Base de datos propia.** PostgreSQL 18 en un contenedor, con un volumen persistente y sin puertos publicados. La primera vez que el volumen está vacío, `deploy/postgres/roles.production.sql` crea solo `barberhub`, el rol dueño y el de la aplicación (sin `BYPASSRLS`, sin superusuario) y la extensión `btree_gist`. `database/roles.sql` sigue siendo el de desarrollo y la CI. Las migraciones se aplican con `--database=pgsql_admin`.

**Archivos.** Las imágenes que suben las barberías (`storage/app/public`) van en un volumen propio del servicio `app`.

**Despliegue manual.** `deploy/deploy.sh` actualiza el código (`git pull --ff-only`), construye las imágenes, aplica las migraciones, reinicia lo que cambió, avisa a los workers (`queue:restart`) y comprueba `/salud`. Cada imagen lleva la etiqueta del commit, así que revertir es arrancar la etiqueta anterior. La automatización es del #116.

**Copias de seguridad.** `deploy/backup.sh` hace un `pg_dump` y una copia de los archivos subidos, los verifica y borra lo que supera la retención (7 días por defecto). Llevar la copia fuera del servidor y probar la restauración son pasos manuales (`docs/deployment.md`). Un monitor externo (UptimeRobot) consulta `/salud`, también manual.

**Proxies de confianza.** `bootstrap/app.php` confía en las cabeceras `X-Forwarded-*` solo de las direcciones de `TRUSTED_PROXIES`, por defecto `172.16.0.0/12` (el rango de las redes de Docker); el compose fija la red `172.28.0.0/16` y la pasa en la variable. Con la conexión FastCGI de Caddy, PHP ya recibe `HTTPS=on` y la dirección real del cliente, de modo que la configuración es una segunda línea de defensa y es la que hace falta si algún día hay otro proxy delante (un CDN, por ejemplo).

## Alternativas descartadas

- **PaaS (Railway, Render, Fly.io).** HTTPS incluido, pero WebSockets, varios procesos y volúmenes dependen del plan, y el costo sube.
- **PostgreSQL gestionado.** Respaldos incluidos, pero hay que comprobar que permita roles `NOBYPASSRLS`, `btree_gist` y PostgreSQL 18, de los que depende el aislamiento entre barberías.
- **Dos dominios (`app.` y `api.`).** Exigiría CORS en la API y cambiar el cliente, que usa una ruta relativa.
- **Octane o FrankenPHP.** Una dependencia nueva sin necesidad para esta carga.
- **Servir las imágenes de las barberías desde el proxy.** La ruta de la API aplica `barbershop:public` y deriva la carpeta de la barbería de la URL (`docs/architecture.md`, #46); servirlas aparte la saltaría.

## Consecuencias

- **Un solo nodo.** Si el VPS cae, cae todo; el 99 % de RNF-04 en 14 días tolera unas 3 h 22 min. No hay redundancia.
- **La cola `database` es provisional** (RD-11, PD-03): los workers y la API comparten la base y la carga de #120 la sentirá.
- **La ventana de 14 días de RNF-04 empieza al desplegar.** Cada día de retraso acorta la ventana.
- **Datos de demostración (D9 pendiente).** El sembrador se niega a correr en producción y la base nace vacía. Hasta decidir cómo crear una barbería de demostración, no hay datos con los que probar.
- **El monitoreo no ve la caída total:** solo un monitor externo que llame a `/salud` la detecta (ADR 0013).
- **Los secretos viven en `deploy/.env`** del servidor, con permisos 600. Quien administra el servidor puede leerlos.
- **Las copias de seguridad quedan en el mismo servidor** hasta que se copien fuera a mano. Contienen datos personales.
- **Docker publica sus puertos por encima de `ufw`.** Por eso el compose publica solo 80 y 443 y ninguno de la base ni de Reverb.
- **Reverb solo acepta el origen de `DOMAIN`.** Compara el host del origen, no la URL completa, por lo que `REVERB_ALLOWED_ORIGINS` lleva el host.

## Pendiente para los otros issues

- **#115:** forzar HTTPS en la aplicación (cabecera HSTS, `APP_URL`), comprobar la política de TLS, repasar los secretos y los roles, y verificar `/salud` por HTTPS desde fuera. El compose ya redirige y los roles ya son propios; falta revisarlo y declararlo.
- **#116:** el flujo de GitHub Actions (acceso por SSH, secretos del repositorio, despliegue tras la CI en verde, trabajos separados para api y web y sin 5xx visibles). Hoy el despliegue recrea los contenedores y hay unos segundos de corte.
- **#117:** el planificador (`schedule:run`; hoy no hay tareas programadas, y la purga del monitoreo es oportunista), ajustar los workers (`--max-time`, `--memory`, `--tries`, `--backoff`) y comprobar que una reserva temporal expira en menos de 11 min y que las tareas fallidas aparecen en `/monitoreo`.
- **#120:** la prueba de carga, que ahora tiene destino, y los 14 días de disponibilidad con el monitor externo ya activo.
- **Sin dueño:** copiar los respaldos fuera del servidor y probar la restauración con frecuencia, y el mecanismo para fijar la barbería en los procesos sin sesión (webhooks, tareas), que sigue pendiente de definir.
