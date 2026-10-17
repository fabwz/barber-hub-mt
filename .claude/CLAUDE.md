# BarberHub (barber-hub-mt)

Plataforma web multi-inquilino de reservas y cobro para barberías. Proyecto individual del curso ISW-621 (UTN, Sede San Carlos). Monorepo con dos proyectos: `barberhub-api` (Laravel 13, PHP 8.4, PostgreSQL 18, arquitectura hexagonal con DDD ligero) y `barberhub-web` (Vue con TypeScript).

## Entorno

El desarrollador trabaja en Windows. El shell por defecto de Claude Code es Git Bash: usa comandos compatibles con él, rutas con `/` y saltos de línea LF.

## Regla principal

Si no sabes algo, hay ambigüedad o dos documentos se contradicen, **pregunta antes de seguir**. No asumas ni inventes requisitos, reglas, endpoints, nombres de tablas ni decisiones de arquitectura.

## Fuentes de verdad (léelas bajo demanda; no las cargues completas)

- **Issue de GitHub:** `gh issue view <N>`. Trae la historia, los criterios de aceptación, la línea "Base:" hacia la referencia y los requisitos y reglas que aplica.
- `context/requirements.md`: busca la ficha del RF-xx, RNF-xx o RD-xx que cite el issue.
- `context/business-rules.md`: busca las reglas citadas (por ejemplo RE-04, PA-03, MT-03).
- `docs/`: cómo está construido el sistema (arquitectura, base de datos, modelo de datos, lenguaje ubicuo y registros de decisión).
- **Referencia del docente (solo lectura):** `../SodaYa-Hexagonal`; la API está en `sodaya-api-hexagonal/`. Cuando un issue diga "Base: X", lee X allí y adáptalo a barbería. No copies contenido del dominio de sodas (platos, categorías, menú). Nunca edites ese repositorio.
- `context/` solo cambia cuando el dueño del proyecto lo pide. Si encuentras una contradicción, repórtala.

## Cómo trabajar cada issue

1. Lee el issue, los requisitos y reglas que cita y la "Base:" de la referencia.
2. Presenta un plan breve y espera confirmación antes de editar archivos.
3. Implementa solo lo que pide el issue: nada de extras ni refactors ajenos.
4. Antes de terminar, deja en verde `composer lint`, `composer analyse` y `composer test` (cuando existan, issues #28 a #30).
5. Si cambia la API, regenera `openapi/v1.json` con `composer openapi`. Si cambia el esquema, la migración es reversible y se actualiza `docs/data-model.md`.
6. Al final explica en pocas líneas las decisiones no triviales: el dueño debe poder explicar y modificar cualquier parte del código en vivo.

## Git y GitHub

- **El dueño ejecuta todo comando de git que modifique algo y todo comando de GitHub.** Tú solo lees: `git status`, `git diff`, `git log`, `git show` y `git branch --show-current`, además de `gh issue view` y `gh issue list`, `gh pr view`, `gh pr list`, `gh pr diff` y `gh pr checks`, `gh run view` y `gh run list`.
- No ejecutes, salvo que el dueño te lo ordene de forma expresa en esa misma conversación: `git add`, `git commit`, `git push`, `git switch`, `git checkout`, `git restore`, `git stash`, `git merge`, `git rebase`, `git tag`, ni ningún `gh` que cree, edite, comente, cierre, fusione o elimine algo (incluido `gh api` con un método distinto de GET).
- Trabajas en la rama del issue, que crea y activa el dueño. Si `git branch --show-current` muestra `main`, detente y avísale.
- Edita los archivos y, al terminar, entrega los commits **propuestos**, uno por cada cambio atómico: los archivos que incluye (el `git add` que debe ejecutar el dueño) y el mensaje completo.
- Mensajes de commit: en inglés, Conventional Commits con alcance, `<type>(<scope>): <description>`, con el pie obligatorio `Refs: #<N>`. Tipos: `feat`, `fix`, `docs`, `test`, `chore`, `build`, `ci`, `style`, `refactor`. Ejemplo: `feat(availability): compute free slots from business and staff hours` con el pie `Refs: #53`.
- Para el pull request, entrega el título (igual al del issue) y la descripción con `Closes #<N>`, y la lista de verificación de la plantilla, listos para pegar. Se integra con merge commit y con la CI en verde.
- Nunca propongas force push, `--no-verify` ni commits con secretos.

## Idioma (RD-07)

- **Inglés:** clases, métodos, variables, comentarios, tablas, columnas y commits.
- **Español:** rutas de la API, atributos JSON, mensajes al usuario y tipos de error.
- Ningún mensaje al usuario va en el código: las excepciones llevan una clave de traducción y el texto vive en `lang/es` (ADR 0008).
- Términos del negocio: barbería = `Barbershop`. Consulta `docs/ubiquitous-language.md` antes de nombrar algo nuevo.

## Arquitectura (api)

- Namespace `Src\`. Estructura `src/<Contexto>/<Módulo>/{Domain,Application,Infrastructure}`; lo común a un contexto va en su módulo `Shared`, y lo común a todos en `src/Shared`.
- Dependencias hacia adentro: Infrastructure, luego Application, luego Domain. Domain solo usa PHP y `Src\Shared\Domain`; Application no conoce Laravel ni Infrastructure. La suite `Architecture` lo verifica.
- Un caso de uso por clase, con un único método público `execute`; recibe puertos por constructor. Los DTO llevan datos primitivos. Los puertos (interfaces) viven en `Domain/Contracts`; los adaptadores, en Infrastructure.
- Entidades y agregados en PHP puro: constructor privado, `create()` para nacer y `reconstitute()` para leer de la base. Agregados solo donde hay invariantes: reserva, bono adquirido, oferta y pago.
- Eloquent solo en `Infrastructure/Persistence`; los modelos terminan en `Model` y nunca salen de esa capa. El repositorio traduce entre entidad y modelo.
- Sin sufijo de tipo: interfaces, objetos de valor, DTO y casos de uso. Las excepciones terminan en `Exception`.
- Los contextos colaboran por un puerto o un evento de dominio, nunca importando el dominio del otro.
- Cada módulo tiene su service provider (registrado en `bootstrap/providers.php`) y su `routes.php`. Controladores delgados; Form Request valida la forma; API Resource expone solo los campos permitidos.
- El tiempo entra como parámetro (`DateTimeImmutable`): el dominio nunca consulta la hora.
- Errores: excepciones de dominio traducidas a RFC 9457 (`application/problem+json`) con `X-Request-Id`. Tipos estables de negocio: `espacio-no-disponible`, `saldo-insuficiente`, `plazo-vencido`, `bono-vencido` y `oferta-vencida`.

## Multi-inquilino (crítico)

- Toda tabla operativa lleva la columna de la barbería; sus llaves únicas y foráneas la incluyen. Seguridad a nivel de fila habilitada y forzada, con una política por tabla (ver `docs/database.md`). Cada método de repositorio recibe la barbería y filtra por ella.
- En las rutas, `{barberia}` es el UUID v7 de la barbería (RD-18). Nunca tomes la barbería del cuerpo, de la consulta ni de un encabezado. El contexto se establece solo después de verificar que la sesión tiene rol en esa barbería.
- Sin rol en la barbería o recurso de otra barbería: 404, con el mismo cuerpo que un recurso inexistente. Rol insuficiente: 403.
- Excepciones de RD-18: las operaciones de cliente que crean la primera membresía (RF-11, RF-27 y RF-30; regla UP-09), la propuesta de IA (RF-37) y las lecturas de datos propios (lista vacía). Un dueño o colaborador que intenta una operación de cliente en su propia barbería recibe 403 `prohibido`.
- Cada recurso nuevo agrega su caso a la suite de aislamiento (`tests/Feature/Tenancy`) y a la matriz de permisos.
- Procesos sin sesión (webhooks, colas, tareas programadas): el mecanismo para fijar la barbería está pendiente de definir. No lo inventes; pregunta.

## Base de datos

- PostgreSQL 18, bases `barberhub` (desarrollo) y `barberhub_testing` (solo pruebas). Dos roles: el dueño de las tablas ejecuta las migraciones por la conexión administrativa (en la referencia, `--database=pgsql_admin`) y el rol de la aplicación atiende las peticiones sin ser dueño ni superusuario.
- Identificadores UUID v7 generados por la aplicación (con `DEFAULT uuidv7()` de respaldo), dinero en colones enteros (nunca decimales), `timestamptz`, zona horaria `America/Costa_Rica`. Tablas y columnas en inglés y `snake_case`. Rangos del negocio con `CHECK`; intervalos sin traslape con `EXCLUDE`.
- Toda migración tiene su `down()`. Revisa la lista de `docs/data-model.md` antes de crear una tabla.
- Nunca ejecutes `migrate:fresh` ni pruebas contra `barberhub`.

## Pruebas

- PHPUnit 12 (no Pest), con las suites `Unit` (sin framework ni base de datos), `Feature` (PostgreSQL real, solo `barberhub_testing`) y `Architecture`.
- Pasarelas de pago, proveedor de IA y correo se simulan en las pruebas. Las pruebas de concurrencia usan conexiones paralelas reales.

## Seguridad y datos

- Nunca leas ni imprimas `.env`. Los secretos viven solo en variables de entorno; `.env.example` va completo y sin valores reales.
- Nunca registres contraseñas, tokens ni datos de contacto.
- Todos los datos de demostración son ficticios. Los pagos usan solo el modo de pruebas de las pasarelas. El sistema no se publica como servicio comercial.
- Precios en colones con IVA de 13 % incluido y desglosado al cliente.

## Alcance

- Los extras (propinas, WhatsApp, Google Calendar y factura electrónica) se construyen solo cuando el núcleo esté terminado.
- No implementes nada fuera de los issues del milestone en curso.