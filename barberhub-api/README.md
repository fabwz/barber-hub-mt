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

| Variable | Para qué sirve |
| --- | --- |
| `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL` | Nombre, ambiente, llave (la genera `php artisan key:generate`), depuración y dirección de la aplicación. |
| `APP_TIMEZONE` | Zona horaria (`America/Costa_Rica`). |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE` | Idiomas (`es`, `en`) y datos de Faker. |
| `APP_MAINTENANCE_DRIVER` | Controlador del modo de mantenimiento. |
| `BCRYPT_ROUNDS` | Rondas de bcrypt para las contraseñas. |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL` | Registro de la aplicación. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_SSLMODE` | Conexión a PostgreSQL (base `barberhub`). |
| `DB_USERNAME`, `DB_PASSWORD` | Rol de la aplicación (`barberhub_app`). |
| `DB_ADMIN_USERNAME`, `DB_ADMIN_PASSWORD` | Rol dueño (`barberhub_owner`), usado por `--database=pgsql_admin`. |
| `QUEUE_CONNECTION` | Controlador de la cola. Es `sync` de forma temporal (RD-11 está pendiente). |
| `PAYMENTS_DEFAULT_GATEWAY` | Pasarela por defecto: `onvo` o `paypal`. |
| `ONVO_SECRET_KEY`, `ONVO_BASE_URL`, `ONVO_RETURN_URL`, `ONVO_SINPE_DESTINATION`, `ONVO_WEBHOOK_SECRET` | ONVO Pay. Solo se acepta una llave de pruebas (`onvo_test_secret_key_…`). |
| `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_BASE_URL`, `PAYPAL_RETURN_URL`, `PAYPAL_CANCEL_URL`, `PAYPAL_CRC_PER_USD`, `PAYPAL_WEBHOOK_ID` | PayPal, solo *sandbox*. |
| `GEMINI_API_KEY`, `GEMINI_MODEL`, `GEMINI_BASE_URL` | Proveedor de lenguaje de la reserva por frase (RF-37). Sin la llave, el extractor queda «no disponible» y no llama a Google. `GEMINI_MODEL` es obligatorio y no se escribe en el código. |
| `AI_CIRCUIT_FAILURES`, `AI_CIRCUIT_WINDOW_SECONDS`, `AI_CIRCUIT_OPEN_SECONDS`, `AI_CIRCUIT_CACHE_STORE` | Circuito de corte del proveedor de IA (IA-05, RNF-11) y la caché que guarda su estado. |

El detalle de las variables de las pasarelas, con sus valores por defecto y los avisos (*webhooks*), está en la sección «Cobro con dos pasarelas» del [README de la raíz](../README.md#cobro-con-dos-pasarelas). El comentario de cada variable en `.env.example` explica su valor por defecto.
