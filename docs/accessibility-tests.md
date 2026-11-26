# Pruebas de accesibilidad del cliente web

Cómo se verifica WCAG 2.2 AA (RNF-27, AC-01) en `barberhub-web`, qué cubre cada prueba, qué queda fuera y cómo registrar una pantalla nueva. Issue #109.

## Qué se prueba y con qué

| Qué | Prueba | Cómo |
| --- | --- | --- |
| Estructura y etiquetas de cada pantalla | `src/__tests__/a11y/screens.spec.ts` | `axe-core` 4.13.0 en jsdom, con las etiquetas `wcag2a`, `wcag2aa`, `wcag21aa` y `wcag22aa` (sin `best-practice`), sobre la pantalla completa montada con `App` (su padre, `<main>` y `<h1>` incluidos). |
| Teclado básico de cada pantalla | `screens.spec.ts` (`expectKeyboardReady`) | Todo elemento interactivo visible y habilitado puede recibir el foco, tiene nombre accesible y no hay ningún `tabindex` positivo. |
| Contraste de color | `src/__tests__/themeTokens.spec.ts` | Calcula los pares de `DESIGN.md` («Contraste verificado») con los valores de `main.css`, en el tema claro y en el oscuro. |
| Texto «Muy grande» (150 %) | `src/__tests__/textSize.spec.ts` | Comprueba las reglas CSS que escalan la raíz con porcentajes. |
| Movimiento | `src/__tests__/reducedMotion.spec.ts` | Comprueba la regla única de `main.css`. |
| Las pruebas de axe fallan cuando deben | `src/__tests__/a11y/helpers.spec.ts` | Un botón sin nombre, una imagen sin `alt`, un campo sin etiqueta, un control sin foco o un `tabindex` positivo hacen fallar los ayudantes. |

## Reglas de axe que se apagan, y por qué

Solo las que jsdom no puede evaluar. Están escritas en `src/__tests__/a11y/helpers.ts`.

- `color-contrast`: jsdom no carga `main.css`, así que ningún elemento tiene un color real. El contraste lo garantiza `themeTokens.spec.ts`.
- `target-size`: jsdom no tiene layout (toda caja mide 0 × 0).

## Alcance real: lo que esta prueba no ve

jsdom no calcula estilos ni layout. El DOM es el mismo en el tema claro, en el oscuro y con «Muy grande» (solo cambian atributos de `<html>`), así que axe se ejecuta una vez por pantalla, en estado cargado. Queda pendiente, para un issue de seguimiento con navegador real:

- Contraste real de cada texto sobre su fondo, en los temas claro y oscuro.
- Reflow con «Muy grande»: que no haya desplazamiento horizontal (AC-06).
- Objetivos táctiles de 24 por 24 px (RNF-27; `DESIGN.md` pide 44 px).
- Orden de tabulación, trampas de foco y que cada control responda al teclado.
- Foco visible medido (el contorno de `:focus-visible`).
- Título descriptivo por ruta: hoy solo `ShopView` cambia `document.title`; las demás pantallas dicen «BarberHub».
- Los estados de carga, error y vacío: se prueba el estado cargado de cada pantalla.

## Cómo registrar una pantalla nueva

La prueba `every route of the router is in the registry or exempt` recorre las rutas con nombre del router (hijas incluidas) y falla si una no está registrada.

1. Agregue la ruta al router con su `name`.
2. En `src/__tests__/a11y/screens.ts`, agregue una entrada a `SCREENS` con el nombre de la ruta:
   - `session`: `guest` (sin sesión), `client` (con sesión) u `owner` (con sesión y la API simulada responde que la persona es dueña de la barbería).
   - `prepare()`: instala la API simulada reutilizando los ayudantes de `src/__tests__/helpers/` y devuelve la dirección que se visita.
   - `knownAccount: false` solo si la pantalla depende de que el router lea la cuenta (contraseña temporal).
3. Si la ruta no es una pantalla (solo redirige o solo existe en desarrollo), agréguela a `EXEMPT` con el motivo escrito.
4. Ejecute `npm run test -- a11y` desde `barberhub-web`. Si axe falla, la salida dice la regla, el nodo y el enlace de ayuda.

Una pantalla que no sea una ruta (un diálogo, un menú) se prueba al abrir la pantalla que la contiene.
