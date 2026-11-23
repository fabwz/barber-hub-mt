# barberhub-api

API REST de BarberHub: Laravel 13, PHP 8.4 y PostgreSQL 18, con arquitectura hexagonal y DDD ligero (`src/<Contexto>/<Módulo>/{Domain,Application,Infrastructure}`). Los comandos de este documento se ejecutan dentro de `barberhub-api`.

## Requisitos

- PHP 8.4 con las extensiones `intl` y `pdo_pgsql` (las que instala la CI).
- Composer 2.
- PostgreSQL 18, con un superusuario para el primer paso de la instalación.
- Certificados raíz en PHP para las llamadas HTTPS a los proveedores (ver «Requisitos para probarlo» en el [README de la raíz](../README.md#requisitos-para-probarlo)).

## Instalación

```sh
composer install
cp .env.example .env
php artisan key:generate
```

1. **Roles y bases.** Una sola vez por servidor, con un superusuario de PostgreSQL. Las contraseñas se pasan como variables de `psql` y nunca se versionan:

   ```sh
   psql -U postgres -v "owner_password=..." -v "app_password=..." -f database/roles.sql
   ```

   Crea los roles `barberhub_owner` (dueño de las tablas) y `barberhub_app` (el que atiende las peticiones) y las bases `barberhub` y `barberhub_testing`.

2. **Contraseñas en `.env`.** `DB_PASSWORD` es la de `barberhub_app` y `DB_ADMIN_PASSWORD` la de `barberhub_owner`.

3. **Migraciones**, siempre con el rol dueño:

   ```sh
   php artisan migrate --database=pgsql_admin
   ```

4. **Datos de demostración** (tres barberías ficticias, idempotente):

   ```sh
   php artisan db:seed
   ```

## Ejecución

```sh
php artisan serve
```

La API queda en `http://localhost:8000` (`APP_URL`), bajo `/api/v1`. El cliente web (`barberhub-web`) la consume por el proxy de desarrollo de Vite.

## Pruebas y comprobaciones

| Comando | Qué hace |
| --- | --- |
| `composer lint` | Verifica el estilo con Pint (`pint --test`). |
| `composer format` | Aplica el estilo con Pint. |
| `composer analyse` | Análisis estático con Larastan (`phpstan analyse --memory-limit=1G`). Es lento. |
| `composer test` | Ejecuta `php artisan config:clear` y luego `php artisan test`. |
| `php artisan test` | La suite completa (`Unit`, `Feature` y `Architecture`), sin el límite de tiempo de Composer. |
| `composer openapi` | Regenera `openapi/v1.json` (`scramble:export`). Se ejecuta cuando cambia la API. |

- **`composer test` puede agotar los 300 s** que Composer concede a un script. Si ocurre, ejecute `php artisan test` directamente: corre la misma suite completa, que es lo que hace la CI.
- Para una sola prueba: `php artisan test --filter=NombreDeLaPrueba`.
- Las pruebas `Feature` usan PostgreSQL real y solo la base `barberhub_testing` (`phpunit.xml`); nunca se ejecutan contra `barberhub`. Ninguna prueba llama a un proveedor real: las pasarelas y la IA se simulan con `Http::fake()`.
- La CI (`.github/workflows/ci.yml`) ejecuta `composer lint`, `composer analyse`, `php artisan test` y un análisis de secretos con gitleaks.

## Variables de entorno

Se definen en `.env`, copia de `.env.example`. El `.env` no se versiona y nunca se escriben secretos en el repositorio.

| Variable | Para qué sirve | Ejemplo | ¿Secreta? |
| --- | --- | --- | --- |
| `APP_NAME` | Nombre de la aplicación. | `BarberHub` | No |
| `APP_ENV` | Ambiente de ejecución. | `local` | No |
| `APP_KEY` | Llave de cifrado de la aplicación; la genera `php artisan key:generate`. | _(la genera Artisan)_ | **Sí** |
| `APP_PREVIOUS_KEYS` | Llaves anteriores, para rotar `APP_KEY` sin invalidar lo cifrado (opcional). | _(vacía)_ | **Sí** |
| `APP_DEBUG` | Depuración; en producción debe ser `false`. | `true` | No |
| `APP_URL` | Dirección de la aplicación. | `http://localhost:8000` | No |
| `APP_TIMEZONE` | Zona horaria. | `America/Costa_Rica` | No |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE` | Idioma de los mensajes, idioma de respaldo y datos de Faker. | `es`, `en`, `es_ES` | No |
| `APP_MAINTENANCE_DRIVER`, `APP_MAINTENANCE_STORE` | Controlador y almacén del modo de mantenimiento. | `file` | No |
| `BCRYPT_ROUNDS` | Rondas de bcrypt para las contraseñas. | `12` | No |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL` | Canal, pila y nivel del registro. Nunca se registran contraseñas, tokens ni llaves (SE-01, RNF-08). | `stack`, `single`, `debug` | No |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_SSLMODE`, `DB_URL`, `DB_CHARSET` | Conexión a PostgreSQL (base `barberhub`). `DB_URL` y `DB_CHARSET` son opcionales. | `pgsql`, `127.0.0.1`, `5432`, `barberhub`, `prefer` | No |
| `DB_USERNAME` | Rol de la aplicación, que atiende las peticiones sin ser dueño ni superusuario. | `barberhub_app` | No |
| `DB_PASSWORD` | Contraseña del rol de la aplicación. | _(vacía)_ | **Sí** |
| `DB_ADMIN_USERNAME` | Rol dueño de las tablas, usado por las migraciones (`--database=pgsql_admin`). | `barberhub_owner` | No |
| `DB_ADMIN_PASSWORD` | Contraseña del rol dueño. | _(vacía)_ | **Sí** |
| `QUEUE_CONNECTION` | Controlador de la cola. Es `database` de forma provisional (RD-11 y PD-03 están pendientes): vence las reservas temporales, ofrece los espacios liberados y vence las ofertas de la lista de espera. Hay que levantar un worker con `php artisan queue:work`; sin él nada de eso ocurre en desarrollo. Las pruebas usan `sync`. | `database` | No |
| `CACHE_STORE` | Almacén de caché de la aplicación (opcional, comentada en `.env.example`). | `database` | No |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Correo (opcionales y comentadas en `.env.example`): ninguna función envía correo todavía; con `log` el mensaje se escribe en el registro. | `log`, `127.0.0.1`, `2525` | No |
| `MAIL_PASSWORD` | Contraseña del servidor de correo, cuando se elija uno. | _(vacía)_ | **Sí** |
| `PAYMENTS_DEFAULT_GATEWAY` | Pasarela por defecto para barberías que no eligieron una: `onvo` o `paypal` (PA-02). | `onvo` | No |
| `ONVO_SECRET_KEY` | Llave secreta de pruebas de ONVO Pay (RF-20); una llave `onvo_live_…` se rechaza. | `onvo_test_secret_key_…` | **Sí** |
| `ONVO_WEBHOOK_SECRET` | Secreto de firma del *webhook* de ONVO. Sin él, los avisos se rechazan con 503. | `whsec_…` | **Sí** |
| `ONVO_BASE_URL`, `ONVO_RETURN_URL`, `ONVO_SINPE_DESTINATION` | URL base de la API de ONVO (por defecto `https://api.onvopay.com/v1`), dirección de retorno tras 3DS y número SINPE Móvil de destino (por defecto `+506 70196686`). | `https://api.onvopay.com/v1` | No |
| `PAYPAL_CLIENT_ID` | Identificador de la aplicación *sandbox* de PayPal. | _(vacía)_ | No |
| `PAYPAL_CLIENT_SECRET` | Secreto de la aplicación *sandbox* de PayPal. | _(vacía)_ | **Sí** |
| `PAYPAL_WEBHOOK_ID` | Identificador del *webhook* de PayPal. Sin él, los avisos se rechazan con 503. | _(vacía)_ | No |
| `PAYPAL_BASE_URL`, `PAYPAL_RETURN_URL`, `PAYPAL_CANCEL_URL`, `PAYPAL_CRC_PER_USD` | URL base (por defecto `https://api-m.sandbox.paypal.com`), direcciones de retorno y cancelación, y colones por dólar (por defecto 500). | `https://api-m.sandbox.paypal.com`, `500` | No |
| `GEMINI_API_KEY` | Llave del proveedor de lenguaje de la reserva por frase (RF-37). Sin ella, el extractor queda «no disponible» y no llama a Google. | _(vacía)_ | **Sí** |
| `GEMINI_MODEL`, `GEMINI_BASE_URL` | Modelo (obligatorio, no se escribe en el código) y URL base del proveedor. | `https://generativelanguage.googleapis.com/v1beta` | No |
| `AI_CIRCUIT_FAILURES`, `AI_CIRCUIT_WINDOW_SECONDS`, `AI_CIRCUIT_OPEN_SECONDS` | Circuito de corte del proveedor de IA (IA-05, RNF-11): fallos seguidos, ventana y tiempo abierto, en segundos. | `3`, `60`, `60` | No |
| `AI_CIRCUIT_CACHE_STORE` | Caché que guarda el estado del circuito; no es la de la base de datos a propósito. | `file` | No |

Las marcadas como secretas solo viven en el `.env` real o en las variables del servidor: la CI escanea el historial con gitleaks y el registro nunca las escribe. El detalle de las variables de las pasarelas, con sus valores por defecto y los avisos (*webhooks*), está en la sección «Cobro con dos pasarelas» del [README de la raíz](../README.md#cobro-con-dos-pasarelas). El comentario de cada variable en `.env.example` explica su valor por defecto.
