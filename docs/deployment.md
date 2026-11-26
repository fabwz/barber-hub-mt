# Despliegue en producción

Procedimiento manual para publicar BarberHub en un VPS (#136). La decisión y sus alternativas están en el [ADR 0014](adr/0014-single-vps-deployment-with-docker-compose.md); los archivos, en la carpeta [`deploy/`](../deploy/). La automatización desde `main` es del #116, el endurecimiento de HTTPS del #115 y los workers y el planificador finos del #117.

> **Solo pruebas.** El sistema no se publica como servicio comercial (RL-05). Las pasarelas se usan únicamente en modo de pruebas: los adaptadores rechazan una llave `onvo_live_…` y la URL de producción de PayPal. Ningún dato real de clientes.

## Qué se despliega

```
Internet ──80/443──▶ proxy (Caddy, TLS automático)
                      ├─ /               archivos estáticos de la web (SPA)
                      ├─ /api/v1, /salud, /monitoreo ──▶ app (PHP-FPM) ──▶ db (PostgreSQL 18)
                      └─ /app (wss) ───────────────────▶ reverb
worker-default   cola `default`: correos, recordatorios, vencimientos, lista de espera ──▶ db
worker-realtime  cola `realtime` (--sleep=1): publica la agenda en vivo ──▶ reverb
```

| Servicio | Imagen | Datos persistentes |
| --- | --- | --- |
| `db` | `postgres:18` | volumen `pgdata` |
| `app`, `worker-default`, `worker-realtime`, `reverb` | `barberhub-api:<commit>` (una sola) | `app` monta el volumen `media` (logos, banners e imágenes de servicios) |
| `proxy` | `barberhub-web:<commit>` (Caddy con la web compilada) | volúmenes `caddy_data` (certificados) y `caddy_config` |

Solo el proxy publica puertos (80 y 443). La base de datos, PHP-FPM y Reverb no son alcanzables desde fuera.

## Qué necesita

- **VPS:** Linux (Ubuntu 24.04 LTS u otra reciente), mínimo **2 vCPU, 2 GB de RAM y 20 GB de disco** (4 GB si se quiere holgura para la compilación de la web, que se hace en el propio servidor). Una IP pública fija.
- **Un subdominio gratuito** (por ejemplo, DuckDNS) que apunte a esa IP, o un dominio propio.
- **Una cuenta de Resend** con un dominio verificado, y las cuentas de pruebas de ONVO, PayPal y Google AI Studio, solo si se van a probar esas funciones. Sin ellas, el resto funciona y esas funciones responden como no disponibles.
- **Monitor externo:** una cuenta gratuita de UptimeRobot.

## Primer despliegue, paso a paso

Se asume un usuario con `sudo` en el servidor (no `root` directo) y la clave SSH ya cargada.

### 1. Preparar el servidor

```bash
sudo apt update && sudo apt -y upgrade
sudo apt -y install ca-certificates curl git ufw
```

**Docker** (con Compose v2) desde el repositorio oficial de Docker; sigue su guía para tu distribución: <https://docs.docker.com/engine/install/ubuntu/>. Después:

```bash
sudo usermod -aG docker "$USER"   # cierra la sesión y vuelve a entrar
docker compose version            # debe mostrar v2
```

**Cortafuegos: solo 22, 80 y 443.**

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo ufw status
```

Docker publica sus puertos por encima de `ufw`, y por eso el compose publica únicamente 80 y 443. Si el proveedor ofrece un cortafuegos en su panel, ábrelo igual solo a 22, 80 y 443.

### 2. DNS

Crea el subdominio (por ejemplo `mibarberhub.duckdns.org`) y apúntalo a la IP pública del servidor con un registro A. Comprueba antes de seguir:

```bash
dig +short mibarberhub.duckdns.org   # debe mostrar la IP del servidor
```

Sin DNS correcto, Let's Encrypt no podrá emitir el certificado (necesita llegar al puerto 80).

### 3. Obtener el código

```bash
sudo mkdir -p /opt/barberhub && sudo chown "$USER": /opt/barberhub
git clone https://github.com/fabwz/barber-hub-mt.git /opt/barberhub   # si es privado: una deploy key de solo lectura
cd /opt/barberhub/deploy
```

### 4. Crear `deploy/.env`

```bash
cp .env.production.example .env
chmod 600 .env
nano .env
```

Rellena cada valor según la tabla de abajo. Los puntos que más fallan:

- `DOMAIN`: solo el nombre, sin `https://`. Repítelo a mano en `ONVO_RETURN_URL`, `PAYPAL_RETURN_URL` y `PAYPAL_CANCEL_URL`.
- Contraseñas y llaves: genera cada una aparte con `openssl rand -hex 24` (letras y dígitos, sin caracteres que escapar). `REVERB_APP_ID`, `REVERB_APP_KEY` y `REVERB_APP_SECRET` también.
- `APP_KEY` y `MONITOR_PASSWORD_HASH` se generan en el paso 5; el hash va entre **comillas simples**.
- Las pasarelas, solo con llaves de pruebas.

Nunca subas este archivo al repositorio ni lo pegues en un chat o un registro.

### 5. Llave de la aplicación y contraseña del panel

Antes del primer arranque faltan dos valores que se generan con la propia imagen. Rellena primero en `.env` las contraseñas y el dominio (paso 4); luego:

```bash
cd /opt/barberhub/deploy
docker compose build app
# La llave de la aplicación: copia la salida a APP_KEY en .env
docker compose run --rm --no-deps app php artisan key:generate --show
# El hash de la contraseña del panel /monitoreo: copia la salida a MONITOR_PASSWORD_HASH='...' (entre comillas simples)
docker compose run --rm --no-deps app php -r "echo password_hash('la-contraseña-que-elijas', PASSWORD_BCRYPT), PHP_EOL;"
```

Guarda la contraseña del panel en tu gestor de contraseñas: solo el hash vive en el servidor. No dejes la contraseña real en el historial de la terminal (antepón un espacio al comando si `HISTCONTROL=ignorespace`, o bórrala con `history -d`).

### 6. Primer arranque: la base y las migraciones

```bash
./deploy.sh --no-pull
```

El script construye las imágenes (la web tarda unos minutos), arranca `db` y espera a que responda. **La primera vez**, PostgreSQL crea el volumen y ejecuta `postgres/roles.production.sql`: la base `barberhub`, el rol dueño `barberhub_owner`, el rol de la aplicación `barberhub_app` (sin `BYPASSRLS`, sin superusuario) y la extensión `btree_gist`. Luego aplica las migraciones con el rol dueño, levanta todo y consulta `/salud`. Si algo falla se detiene y dice dónde mirar; el script es idempotente y se puede repetir.

> Los roles solo se crean cuando el volumen `pgdata` está vacío. Cambiar `DB_PASSWORD` o `DB_ADMIN_PASSWORD` en `.env` después no cambia la contraseña de la base: cámbiala también con `docker compose exec db psql -U postgres -c "ALTER ROLE barberhub_app PASSWORD '...'"`. Para volver a empezar de cero (borra **todos** los datos): `docker compose down -v`.

### 7. Verificar el despliegue

```bash
docker compose ps                                  # todos "running"; db "healthy"
curl -i https://mibarberhub.duckdns.org/salud      # 200  {"estado":"ok","ia":"..."}
curl -I http://mibarberhub.duckdns.org/salud       # 308 redirige a https
```

Abre `https://mibarberhub.duckdns.org/` en el navegador: debe cargar la web con el candado.

**`/salud` debe dar 503 si la base cae** (criterio del #136). Compruébalo una vez:

```bash
docker compose stop db
curl -i https://mibarberhub.duckdns.org/salud      # 503 {"estado":"fallo",...}
docker compose start db
curl -i https://mibarberhub.duckdns.org/salud      # 200 otra vez, en unos segundos
```

**WebSocket (`wss`).** Con la agenda del dueño abierta en el navegador (solo aparece «En vivo» si hay una barbería y una sesión), la consola de red debe mostrar una conexión `wss://mibarberhub.duckdns.org/app/…` en estado 101. Los registros: `docker compose logs --tail=50 reverb worker-realtime`.

**Workers.** `docker compose logs --tail=50 worker-default worker-realtime` no debe mostrar errores. Sin workers, las reservas temporales no expiran y no salen correos.

**Panel de monitoreo.** `https://mibarberhub.duckdns.org/monitoreo` pide usuario y contraseña (`MONITOR_USER` y la contraseña del paso 6). Debe mostrar la consulta de `/salud` que acabas de hacer. Sin los dos valores o con `MONITOR_ENABLED=false`, responde 404.

### 8. Webhooks de las pasarelas (solo si se prueban pagos)

Registra, con la URL pública, en cada panel de pruebas:

- ONVO Pay: `POST https://mibarberhub.duckdns.org/api/v1/pagos/avisos/onvo` (el secreto `whsec_…` va en `ONVO_WEBHOOK_SECRET`).
- PayPal: `POST https://mibarberhub.duckdns.org/api/v1/pagos/avisos/paypal` (el identificador va en `PAYPAL_WEBHOOK_ID`).

Después de cambiar `.env`, aplica con `./deploy.sh --no-pull`.

### 9. Copias de seguridad

`backup.sh` guarda un `pg_dump` y una copia de las imágenes subidas, los verifica y borra lo que tenga más de 7 días (`RETENTION_DAYS`). Los archivos de `/var/backups/barberhub` solo los lee el usuario que los crea, porque contienen datos personales.

```bash
sudo mkdir -p /var/backups/barberhub && sudo chown "$USER": /var/backups/barberhub
/opt/barberhub/deploy/backup.sh          # una prueba a mano
crontab -e
# 15 3 * * * /opt/barberhub/deploy/backup.sh >> /var/log/barberhub-backup.log 2>&1
```

> La retención corta y la copia en el mismo servidor **no protegen si el servidor se pierde**. Pasos manuales, fuera del script:
>
> 1. **Copia fuera del servidor.** Por ejemplo, desde tu equipo: `scp -r usuario@servidor:/var/backups/barberhub ./respaldos` o `rsync -a`. Hazlo cada semana, o programa `rsync` hacia otro lugar.
> 2. **Prueba de restauración.** Al menos una vez (ver «Restaurar»): una copia que nunca se restauró no es una garantía.

### 10. Monitor externo de `/salud` (UptimeRobot)

El panel no ve la caída total del servidor (ADR 0013). Crea en <https://uptimerobot.com> un monitor **HTTP(s)** hacia `https://mibarberhub.duckdns.org/salud`, con intervalo de 5 minutos, que espere una respuesta 200, y agrega tu correo para las alertas. Hazlo **el día del despliegue**: la ventana de 14 días de RNF-04 (99 %) empieza entonces, y el informe de disponibilidad del #120 sale de este monitor.

## Actualizar

```bash
cd /opt/barberhub/deploy
./deploy.sh
```

Actualiza `main` con `git pull --ff-only`, construye las imágenes (etiquetadas con el commit), aplica las migraciones, recrea los contenedores que cambiaron, avisa a los workers y comprueba `/salud`. Si algo falla, se detiene y lo dice. `./deploy.sh --no-pull` vuelve a desplegar lo que ya está descargado.

Hay unos segundos de corte mientras se recrean los contenedores. Evitarlo es del #116.

## Revertir

Cada despliegue deja una imagen etiquetada con su commit: `docker images barberhub-api`.

```bash
cd /opt/barberhub/deploy
IMAGE_TAG=<commit-anterior> docker compose up -d       # las imágenes anteriores siguen en el servidor
curl -i https://mibarberhub.duckdns.org/salud
```

Si el despliegue que se revierte traía **migraciones**, el esquema ya cambió. Dos caminos, en este orden de preferencia:

1. Deshacer las migraciones de ese despliegue (cada una tiene su `down()`): `docker compose run --rm --no-deps app php artisan migrate:rollback --database=pgsql_admin --force --step=<n>`. Hazlo **antes** de arrancar el código anterior y revisa que `down()` no borre datos que importan.
2. Restaurar la copia de seguridad previa al despliegue (abajo). Se pierden los datos posteriores a la copia.

Para dejar fijo el código anterior, en tu equipo reviertes el cambio en `main` por pull request; no se fuerza el historial.

## Restaurar una copia

```bash
cd /opt/barberhub/deploy
docker compose stop app worker-default worker-realtime reverb
docker compose exec -T db dropdb --username postgres barberhub
docker compose exec -T db createdb --username postgres --owner barberhub_owner barberhub
docker compose exec -T db pg_restore --username postgres --dbname barberhub --no-owner --role barberhub_owner \
  < /var/backups/barberhub/barberhub-db-AAAAMMDD-HHMMSS.dump
# Imágenes subidas:
docker compose run --rm --no-deps -T app tar -C /var/www/html/storage/app -xzf - < /var/backups/barberhub/barberhub-media-AAAAMMDD-HHMMSS.tar.gz
docker compose start app worker-default worker-realtime reverb
```

Para probarlo sin tocar producción, restaura en una base con otro nombre (`createdb barberhub_restore`) y cuenta las filas de `reservations`, `barbershops` y `users`. La extensión `btree_gist` y las políticas de seguridad a nivel de fila vienen en el volcado; confirma con `\dx` y `\d+ reservations`.

## Variables de entorno

Todas viven en `deploy/.env` del servidor (copia de [`.env.production.example`](../deploy/.env.production.example)). **Secreta** significa que se genera una sola vez, no se comparte y no se escribe en el repositorio, en un chat ni en un registro.

| Variable | Para qué sirve | ¿Secreta? |
| --- | --- | --- |
| `DOMAIN` | Nombre del dominio, sin esquema. De ella salen `APP_URL`, el origen que acepta Reverb y la dirección del socket. | No |
| `APP_NAME`, `APP_ENV=production`, `APP_DEBUG=false` | Nombre, ambiente y depuración apagada. | No |
| `APP_KEY` | Llave de cifrado de la aplicación (`key:generate --show`). | **Sí** |
| `APP_PREVIOUS_KEYS` | Llaves anteriores, solo al rotar `APP_KEY` (opcional). | **Sí** |
| `APP_TIMEZONE`, `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `BCRYPT_ROUNDS`, `LOG_LEVEL` | Zona horaria, idiomas, rondas de bcrypt y nivel del registro. El registro va a `stderr` (`docker compose logs`). | No |
| `TRUSTED_PROXIES` | Direcciones de los proxies cuyas cabeceras `X-Forwarded-*` se aceptan; la red del compose (`172.28.0.0/16`). Nunca `*`. | No |
| `POSTGRES_PASSWORD` | Superusuario del contenedor de la base: solo para las copias y el mantenimiento. | **Sí** |
| `DB_CONNECTION`, `DB_USERNAME` | Conexión y rol de la aplicación (`barberhub_app`). `DB_HOST`, `DB_PORT` y `DB_DATABASE` los fija el compose. | No |
| `DB_PASSWORD` | Contraseña del rol de la aplicación. | **Sí** |
| `DB_ADMIN_USERNAME` | Rol dueño de las tablas (`barberhub_owner`), usado por las migraciones. | No |
| `DB_ADMIN_PASSWORD` | Contraseña del rol dueño. | **Sí** |
| `DB_SSLMODE` | Modo TLS hacia la base; `prefer` dentro de la red privada. | No |
| `QUEUE_CONNECTION`, `CACHE_STORE`, `AI_CIRCUIT_CACHE_STORE` | Cola `database` (provisional), caché `database` y caché del circuito de la IA. | No |
| `BROADCAST_CONNECTION` | `reverb`. | No |
| `REVERB_APP_ID` | Identificador de la aplicación de Reverb. | **Sí** |
| `REVERB_APP_KEY` | Llave pública de Reverb; también se compila en la web. | No |
| `REVERB_APP_SECRET` | Secreto de Reverb. Nunca llega a la web. | **Sí** |
| `REVERB_PORT`, `REVERB_SCHEME` | Dirección pública del socket (`443`, `https`). El compose usa `reverb:8080` por `http` para los workers. | No |
| `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT` | Dónde escucha el servidor Reverb dentro del contenedor (`0.0.0.0`, `8080`). | No |
| `MAIL_MAILER=resend`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `NOTICES_MAIL_PER_SECOND` | Correo por Resend. El remitente es de un dominio verificado. | No |
| `RESEND_API_KEY` | Llave de Resend. | **Sí** |
| `PAYMENTS_DEFAULT_GATEWAY` | Pasarela por defecto: `onvo` o `paypal`. | No |
| `ONVO_SECRET_KEY` | Llave secreta de pruebas de ONVO (`onvo_test_secret_key_…`). | **Sí** |
| `ONVO_WEBHOOK_SECRET` | Secreto de firma (`whsec_…`) del webhook de ONVO. | **Sí** |
| `ONVO_BASE_URL`, `ONVO_RETURN_URL`, `ONVO_SINPE_DESTINATION` | URL base, retorno tras 3DS y número SINPE Móvil. | No |
| `PAYPAL_CLIENT_ID`, `PAYPAL_WEBHOOK_ID`, `PAYPAL_BASE_URL`, `PAYPAL_RETURN_URL`, `PAYPAL_CANCEL_URL`, `PAYPAL_CRC_PER_USD` | PayPal en modo sandbox. | No |
| `PAYPAL_CLIENT_SECRET` | Secreto de la aplicación sandbox de PayPal. | **Sí** |
| `GEMINI_API_KEY` | Llave del proveedor de lenguaje (RF-37). | **Sí** |
| `GEMINI_MODEL`, `GEMINI_BASE_URL`, `AI_CIRCUIT_FAILURES`, `AI_CIRCUIT_WINDOW_SECONDS`, `AI_CIRCUIT_OPEN_SECONDS` | Modelo, URL y circuito de corte. | No |
| `MONITOR_ENABLED=true`, `MONITOR_USER` | Enciende el panel y fija su usuario. | No |
| `MONITOR_PASSWORD_HASH` | Hash bcrypt de la contraseña del panel, **entre comillas simples**. | **Sí** |
| `FILESYSTEM_MEDIA` | Disco de las imágenes de las barberías (`public`). | No |
| `VITE_ONVO_PUBLISHABLE_KEY`, `VITE_ONVO_API_URL` | Valores públicos de ONVO compilados en la web (solo llaves `onvo_test_`). | No |

`VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT` y `VITE_REVERB_SCHEME` los arma el compose desde `REVERB_APP_KEY` y `DOMAIN`. Las variables `VITE_*` se fijan al **construir** la web: cambiarlas exige `./deploy.sh --no-pull`.

## Operación diaria

| Quiero… | Comando (desde `deploy/`) |
| --- | --- |
| Ver el estado | `docker compose ps` |
| Ver los registros | `docker compose logs --tail=100 -f app worker-default` |
| Reiniciar un servicio | `docker compose restart worker-default` |
| Ver las tareas fallidas | el panel `/monitoreo` (solo nombre de la tarea y clase de la excepción) |
| Espacio en disco | `docker system df` |

## Riesgos conocidos

- **Un solo nodo.** Si el servidor cae, cae todo. El 99 % de RNF-04 en 14 días tolera unas 3 h 22 min.
- **Cola `database` provisional** (RD-11, PD-03): comparte la base con la API; la carga del #120 lo pondrá a prueba.
- **Sin datos de demostración (D9 pendiente).** La base nace vacía y el sembrador se niega a correr en producción; hay que decidir cómo crear una barbería para las pruebas y la defensa.
- **La ventana de 14 días de RNF-04 empieza al desplegar.** Activa el monitor externo ese mismo día.
- **Copias en el mismo servidor.** Sin la copia manual fuera de él, un fallo del disco pierde todo.
- **Corte breve al desplegar.** Se recrean los contenedores (#116).
- **Sin planificador todavía** (#117): la purga del monitoreo es oportunista y no hay tareas programadas.
- **El mecanismo para fijar la barbería en los procesos sin sesión** (webhooks, tareas) sigue pendiente de definir.
