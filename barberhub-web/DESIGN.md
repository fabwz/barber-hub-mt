# BarberHub — Guía de diseño

> Consola oscura, sobria y precisa: un lienzo de grafito frío, líneas finas de 1 px y un solo acento hueso que se usa con contención. Todo es plano, sin sombras ni degradados.

Este documento es la fuente de verdad visual del cliente web (`barberhub-web`: Vue 3, TypeScript y Tailwind CSS 4). Toda interfaz nueva lo sigue. Si algo no está aquí, se elige la opción más sobria y se anota.

**Tema:** oscuro (el claro queda pendiente, ver «Pendiente»).
**Idioma de la interfaz:** español de Costa Rica, tratando de «usted».

## Principios

1. **Una sola interfaz para todas las barberías.** No hay un color por negocio. La identidad de cada barbería viene de sus datos: logo, nombre, dirección, ciudad, teléfono, redes y un banner opcional.
2. **Casi todo es gris.** El hueso es el único color de marca y solo señala foco, selección con ícono, estado «confirmada» y la página activa.
3. **Las superficies se separan con líneas de 1 px**, nunca con sombras.
4. **Cada elemento se gana su lugar.** Pocos elementos por pantalla, mucho aire, jerarquía por tamaño y peso.
5. **Ningún estado depende solo del color o del tono.** Siempre hay texto, ícono o forma.

## Tokens — Color

| Token | Valor | Nombre en el lienzo | Uso |
|---|---|---|---|
| `canvas` | `#161819` | canvas | Fondo de toda pantalla |
| `surface` | `#1c1f21` | surface | Tarjetas, menús, avisos, fondo de hover |
| `elevated` | `#272e32` | bone-surface | Selección, botón tonal, aviso de éxito, página activa |
| `line` | `#2a2f32` | border | Líneas de 1 px, separadores, esqueleto |
| `line-strong` | `#3a3f45` | slate | Borde de botones, chips y fichas (decorativo) |
| `line-hover` | `#454b50` | graphite | Borde al pasar el cursor, íconos tenues |
| `field` | `#6b7073` | field | Borde de campo en reposo |
| `fg` | `#eef0f0` | snow | Texto principal |
| `fg-muted` | `#9a9d9e` | silver | Texto secundario |
| `fg-subtle` | `#85888a` | smoke | Texto terciario: solo sobre `canvas` y `surface` |
| `accent` | `#ece6d6` | bone | Foco, check de selección, punto de «confirmada», página activa, logo |
| `accent-hover` | `#cfc8b5` | bone-hover | Enlaces al pasar el cursor |
| `btn-hover` | `#2e373c` | — | Relleno del botón tonal al pasar el cursor |
| `btn-active` | `#20262a` | — | Relleno del botón tonal presionado |
| `danger` | `#f2a3a0` | error-text | Texto e ícono de error |
| `danger-surface` | `#301c1a` | error-surface | Fondo de avisos y mensajes de error |
| `danger-border` | `#d9706e` | error-border | Borde de campo con error |
| `warning` | `#e8c27a` | warn-text | Texto, ícono y punto de advertencia |
| `warning-surface` | `#2d2616` | warn-surface | Fondo de avisos de advertencia |

El hueso (`#ece6d6`) casi no se distingue del texto (`#eef0f0`, contraste 1,1:1). Por eso **nunca se usa para resaltar texto**: marca con forma, ícono, punto, borde o línea.

### Contraste verificado

| Par | Contraste | Mínimo | Resultado |
|---|---|---|---|
| `fg` sobre `canvas` / `surface` / `elevated` | 15,6 / 14,5 / 12,1 | 4,5 | Cumple |
| `fg-muted` sobre `canvas` / `surface` / `elevated` | 6,5 / 6,1 / 5,1 | 4,5 | Cumple |
| `fg-subtle` sobre `canvas` / `surface` | 5,0 / 4,6 | 4,5 | Cumple |
| `fg-subtle` sobre `elevated` | 3,9 | 4,5 | **No usar** |
| `accent` sobre `canvas` / `elevated` | 14,3 / 11,1 | 3 | Cumple |
| `danger` sobre `danger-surface` | 8,1 | 4,5 | Cumple |
| `warning` sobre `warning-surface` | 8,9 | 4,5 | Cumple |
| `field` sobre `canvas` / `surface` | 3,6 / 3,3 | 3 | Cumple |
| `danger-border` sobre `canvas` | 5,5 | 3 | Cumple |
| `fg` sobre `btn-hover` / `btn-active` | 10,6 / 13,4 | 4,5 | Cumple |

Los campos van sobre `canvas` o `surface`, **nunca sobre `elevated`** (ahí `field` baja a 2,8:1).

## Tokens — Tipografía

**Familia única: Manrope**, pesos **400 y 500** (nunca 600 ni 700). Se carga solo esos dos pesos, con `font-display: swap`. No hay fuente monoespaciada: los datos (horas, precios, teléfonos, códigos) usan Manrope con cifras tabulares (`font-variant-numeric: tabular-nums`, clase `tabular-nums`).

Interletrado global: `-0.007em`. Los títulos usan `text-wrap: balance` y los párrafos `text-wrap: pretty`.

| Rol | Tamaño | Interlineado | Peso | Uso |
|---|---|---|---|---|
| Nombre de barbería | 36 px (2.25rem) | 1,2 | 400 | Solo en su página pública |
| Título de página | 24 px (1.5rem) | 1,33 | 400 | Encabezado de cada pantalla |
| Subtítulo | 18 px (1.125rem) | 1,38 | 500 | Secciones dentro de una pantalla |
| Cuerpo | 16 px (1rem) | 1,5 | 400 o 500 | Texto general y campos |
| Cuerpo pequeño | 14 px (0.875rem) | 1,43 | 400 o 500 | Botones, etiquetas, chips, tablas |
| Pie | 12 px (0.75rem) | 1,5 | 500 | Estados, ayudas, metadatos |

Escala fija en rem; no hay tipografía fluida. Las cifras de precio y hora se muestran con tabulares y con el mismo tamaño que el texto que las acompaña.

## Tokens — Espaciado y formas

- **Base de 8 px.** Con la escala por defecto de Tailwind (1 unidad = 4 px) se usan los múltiplos pares: `2` (8), `4` (16), `5` (20), `6` (24), `8` (32), `12` (48), `16` (64).
- **Márgenes de pantalla:** 20 px en celular (`p-5`) y 32 px en escritorio (`p-8`).
- **Objetivos táctiles:** 44 px como mínimo, en altura y en ancho.

| Forma | Valor | Dónde |
|---|---|---|
| Píldora | `9999px` | Botones, etiquetas, chips |
| Tarjeta | `16px` | Tarjetas, menús, fichas de método de pago, filas de servicio seleccionadas, avisos |
| Campo | `8px` | Campos, mensajes en línea, filas de agenda seleccionadas |
| Círculo | `50%` | Logo, monograma, puntos |

**Líneas:** todas de **1 px**, sin excepción. No existe ningún borde de 2 px.

## Elevación y superficies

| Nivel | Token | Uso |
|---|---|---|
| 0 | `canvas` | Fondo de toda pantalla |
| 1 | `surface` | Tarjetas, menús, avisos, hover |
| 2 | `elevated` | Selección, botón tonal, página activa |

**Cero sombras en cualquier elemento**, incluidos menús, avisos y diálogos: se separan con borde `line` y la superficie del nivel. **Cero degradados**, desenfoque o efectos de vidrio.

## Componentes

### Botón principal (tonal)
Píldora de `48 px` de alto en flujos de pago y `44 px` en el resto. Relleno `elevated`, texto `fg` en peso 500, 14 px, borde de 1 px `line-strong`. Hover: relleno `btn-hover`. Presionado: `btn-active`. Un solo botón principal por región visual. No existe un botón relleno de hueso.

### Botón secundario
Píldora transparente, borde de 1 px `line-strong`, texto `fg` en peso 400. Hover: borde `line-hover`.

### Enlace
Texto `fg` con subrayado. Hover: `accent-hover`. Nunca se resalta con hueso en reposo.

### Chip de espacio horario y de fecha
Píldora de 44 px de alto, padding horizontal de 16 px, texto de 14 px con cifras tabulares.
- **Libre:** transparente, borde 1 px `line-strong`, texto `fg`. Hover: relleno `line`.
- **Seleccionado:** relleno `elevated`, **el mismo borde** 1 px `line-strong`, texto en peso 500 y un **check de 14 px en `accent`** a la izquierda. El check es obligatorio: el tono de `elevated` contra el fondo es de solo 1,3:1 y no basta para identificar el estado. Lleva `aria-pressed="true"`.
- **Ocupado:** borde `line`, texto `fg-subtle` tachado, `aria-disabled="true"` y cursor `not-allowed`. No es seleccionable.
- **Foco de teclado:** contorno de 1 px `accent` con 2 px de separación.
- Los días sin horas disponibles muestran «–» como número y lo anuncian como «sin horas» para lectores de pantalla.

### Fila de servicio (lista de selección)
Filas planas separadas por una línea `line`. Cada fila muestra nombre, duración y precio. Hover: relleno `surface` con radio de 16 px. **Seleccionada:** relleno `elevated`, radio de 16 px, sin línea inferior, texto en peso 500 y un indicador de radio a la derecha (círculo de 1 px `line-strong` con un punto de 10 px en `accent`). Es un grupo `role="radiogroup"` con opciones `role="radio"`.

### Ficha de método de pago
Tarjeta de 16 px con borde 1 px `line-strong`, ícono de 20 px, nombre y una línea de descripción. Hover: borde `line-hover`. Seleccionada: relleno `elevated` con el mismo borde. Mismo patrón de `radiogroup`.

### Campo de texto
Alto de 48 px, radio de 8 px, relleno `canvas`, borde de 1 px `field`, texto `fg`, marcador `fg-subtle`. La etiqueta va arriba, en 14 px y peso 500.
- **Foco:** el borde pasa a `accent` de 1 px, sin halo ni sombra, y se quita el contorno.
- **Error:** borde `danger-border` y debajo un mensaje con ícono de 16 px, en `danger`, que nombra el problema y la salida. Se asocia con `aria-describedby` y `aria-invalid`.
- **Deshabilitado:** borde `line` y texto `fg-subtle`.

### Etiqueta de estado
Un punto de 8 px y el texto, sin relleno. Nunca solo color.

| Estado de la reserva | Texto | Punto |
|---|---|---|
| Temporal | Pendiente de pago | `warning` |
| Confirmada | Confirmada | `accent` |
| Completada | Atendida | `accent` |
| Ausente | Ausente | `danger` |
| Cancelada (por el cliente o por la barbería) | Cancelada | `danger` |
| Expirada | Expirada | Anillo de 1 px `fg-muted`, sin relleno; texto `fg-muted` |

| Estado del pago | Texto | Punto |
|---|---|---|
| Pendiente | Pago pendiente | `warning` |
| Aprobado | Pago aprobado | `accent` |
| Rechazado | Pago rechazado | `danger` |
| Fallido | Pago fallido | `danger` |
| Reembolsado | Reembolsado | Anillo de 1 px `fg-muted` |

La interfaz usa estos textos y no «Pagado», que no es un estado del sistema.

### Fila de agenda
Plana, sin tarjeta. Columnas: rango de hora (tabular), cliente, servicio y etiqueta de estado. Separadas por líneas de 1 px `line`. Seleccionada: relleno `elevated` con radio de 8 px, sin borde, texto en peso 500. En la vista por día, la **hora actual** se marca con un punto de 9 px y una línea de 1 px, ambos en `accent`.

### Navegación del negocio (escritorio)
Barra superior con: Agenda, Reservas, Servicios, Barberos y Pagos. Elemento inactivo: texto `fg-muted`, peso 400. **Página activa:** relleno `elevated`, texto `fg` en peso 500, ícono en `accent` y `aria-current="page"`.

### Aviso
Tarjeta de 16 px con borde de 1 px, sin sombra. Aparece abajo a la derecha en escritorio y arriba en el celular.
- **Éxito:** relleno `surface`, borde `line`, check en `accent` y texto `fg`. `role="status"`.
- **Error:** relleno `danger-surface`, borde `danger-border`, ícono y texto `danger`. `role="alert"`.
Los textos nombran el problema y la salida, sin «Error:» doble ni signos de exclamación.

### Esqueleto de carga
Bloques de `line` con radio de 8 px que imitan la forma del contenido (fila de servicio, bloque de agenda). Nunca ruedas. La animación es un pulso lento de opacidad y se desactiva con `prefers-reduced-motion`. El contenedor lleva `aria-busy="true"`.

### Estado vacío y estado de error
- **Vacío:** un título que nombra el espacio, una línea que explica qué pasa y una acción en verbo. Ejemplo: «No hay reservas para el sáb 7 nov.» y «Ir a hoy».
- **Error de carga:** «Error: no pudimos cargar …», una línea con la salida («Revise su conexión e intente de nuevo. Lo que ya eligió se mantiene.») y el botón «Reintentar». Lo ya elegido por la persona nunca se pierde.

### Cabecera de la barbería (pantallas del cliente)
- **Banner opcional:** proporción 2:1 en celular y 3:1 en escritorio, decorativo (`aria-hidden`). No lleva texto encima.
- **Logo:** círculo de 72 px (88 px en escritorio con banner; 56 px sin banner) con borde de 1 px `line-strong`, posado sobre el borde inferior del banner. Sin logo, un **monograma** con las iniciales de las dos primeras palabras de más de dos letras del nombre, sobre `elevated`.
- **Sin banner:** cabecera compacta, con el logo a la izquierda del nombre.
- **Nombre:** 36 px, peso 400, balanceado.
- **Ubicación:** un botón en píldora con ícono de pin, la dirección con la ciudad y un chevron. Despliega una lista de enlaces dentro de una tarjeta de 16 px con borde `line`: «Ver en Google Maps», «Cómo llegar» y «Abrir en Waze». Los enlaces se arman con la dirección en texto (`https://www.google.com/maps/search/?api=1&query=…`, `https://www.google.com/maps/dir/?api=1&destination=…` y `https://waze.com/ul?q=…&navigate=yes`) y abren en una pestaña nueva con `rel="noopener"`. Se implementa como un botón con `aria-expanded` que despliega una lista de enlaces, **sin** `role="menu"`.
- **Teléfono:** enlace `tel:`, 14 px, cifras tabulares.
- **Redes:** como máximo WhatsApp, Instagram, Facebook y TikTok, con objetivos de 44 px e íconos de 20 px. **Solo se muestran las que el dueño configuró**; no hay enlaces vacíos.
- Se prueban cuatro casos: con logo y banner, solo con logo, sin ninguno de los dos, y con un banner oscuro y de baja calidad.

## Movimiento y foco

- Transiciones de **150 ms** `ease-out`, solo para comunicar un cambio de estado (hover, apertura de un menú). Ninguna animación de entrada.
- **Foco de teclado** (`:focus-visible`): contorno de 1 px `accent` con 2 px de separación, en botones, chips, enlaces y filas.
- **Foco de campo:** el borde pasa a `accent`, sin contorno.
- Selección de texto: relleno `elevated` y texto `fg`.
- Con `prefers-reduced-motion`, las transiciones y animaciones se reducen al mínimo.

## Íconos e imágenes

- **Lucide**, trazo de 1,5 px, tamaños de 16, 20 y 24 px, heredan el color del texto. Nunca emojis ni glifos como íconos.
- El banner y el logo son **del negocio** y se muestran dentro de su marco. BarberHub no agrega fotografías de stock ni ilustraciones decorativas.

## Voz del texto

- Español de Costa Rica, «usted».
- **Botones:** verbo primero, sin punto: «Reservar cita», «Continuar al pago», «Reintentar».
- **Errores:** dicen qué pasó y qué hacer, en una o dos frases. Sin lenguaje técnico ni mensajes crudos del servidor.
- **Formatos:** precios `₡10.000` (punto como separador de miles); horas `10:30`; rangos `10:30–11:15`; fechas «jue 5 nov 2026» y, sin año, «sáb 7 nov».
- Mayúscula solo al inicio de la frase y en nombres propios. Sin signos de exclamación en mensajes del sistema.

## Accesibilidad

- WCAG 2.2 AA: texto de al menos 4,5:1, componentes y foco de al menos 3:1.
- Objetivos de 44 px como mínimo.
- Ningún estado depende solo del color o del tono: la selección lleva check o indicador, y los estados llevan punto y texto.
- `<html lang="es-CR">`. Un solo `h1` por pantalla.
- Chips como `aria-pressed`; servicios y métodos de pago como `radiogroup`; página activa con `aria-current="page"`; avisos con `role="status"` o `role="alert"`; zonas que cargan con `aria-busy`.
- Todo funciona con teclado y respeta `prefers-reduced-motion`.

## Notas para la implementación

- **Pago con tarjeta:** el número, el vencimiento y el CVV **no se envían a nuestra API**. El método de pago se crea directamente con ONVO usando su llave pública, y a nuestro servidor solo llega su identificador.
- **Resumen de pago:** muestra el total de la reserva y, si la barbería cobra un depósito, una línea adicional «Depósito a pagar ahora». Una reserva temporal vence a los 10 minutos y la pantalla debe mostrarlo.
- **Pantallas con sus tres estados:** toda pantalla que carga datos tiene el estado cargando (esqueleto), el de error y el vacío.
- Los tipos de TypeScript se generan desde `openapi/v1.json`; los nombres de campos están en español.

## Qué hacer y qué no

### Hacer
- Separar superficies con líneas de 1 px y cambios de superficie.
- Usar el hueso solo en foco, check de selección, punto de «confirmada», página activa y logo.
- Marcar la selección con tono, borde discreto y un check o indicador.
- Mantener un único botón principal por región.
- Usar Manrope en pesos 400 y 500, con cifras tabulares en datos.
- Escribir mensajes que nombran el problema y la salida.

### No hacer
- Sombras, degradados, desenfoque, vidrio, brillos o halos.
- Bordes de más de 1 px, o bordes de color distinto a los definidos.
- Texto en hueso para resaltar, o un color distinto por barbería.
- Pesos 600 o 700, fuentes monoespaciadas, o mayúsculas sostenidas en etiquetas.
- Emojis como íconos, fotografías de stock o ilustraciones decorativas.
- Radios fuera del vocabulario (píldora, 16, 8 y círculo).
- `fg-subtle` sobre `elevated`, o un campo sobre `elevated`.
- Estados que dependan solo del color.
- Un botón principal relleno de hueso.

## Inicio rápido

### Variables CSS y base

```css
:root {
  color-scheme: dark;
  --canvas: #161819;
  --surface: #1c1f21;
  --elevated: #272e32;
  --line: #2a2f32;
  --line-strong: #3a3f45;
  --line-hover: #454b50;
  --field: #6b7073;
  --fg: #eef0f0;
  --fg-muted: #9a9d9e;
  --fg-subtle: #85888a;
  --accent: #ece6d6;
  --accent-hover: #cfc8b5;
  --btn-hover: #2e373c;
  --btn-active: #20262a;
  --danger: #f2a3a0;
  --danger-surface: #301c1a;
  --danger-border: #d9706e;
  --warning: #e8c27a;
  --warning-surface: #2d2616;
}

body {
  background: var(--canvas);
  color: var(--fg);
  font-family: 'Manrope', ui-sans-serif, system-ui, sans-serif;
  font-size: 1rem;
  line-height: 1.5;
  letter-spacing: -0.007em;
}

:focus-visible { outline: 1px solid var(--accent); outline-offset: 2px; }
input:focus, input:focus-visible, select:focus, select:focus-visible, textarea:focus {
  outline: none;
  border-color: var(--accent);
}
::selection { background: var(--elevated); color: var(--fg); }
a { color: var(--fg); text-decoration: underline; }
a:hover { color: var(--accent-hover); }
* { scrollbar-width: thin; scrollbar-color: var(--line-strong) transparent; }

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { transition-duration: 0.01ms !important; animation-duration: 0.01ms !important; }
}
```

### Tailwind CSS 4

```css
@import "tailwindcss";

@theme {
  --color-canvas: #161819;
  --color-surface: #1c1f21;
  --color-elevated: #272e32;
  --color-line: #2a2f32;
  --color-line-strong: #3a3f45;
  --color-line-hover: #454b50;
  --color-field: #6b7073;
  --color-fg: #eef0f0;
  --color-fg-muted: #9a9d9e;
  --color-fg-subtle: #85888a;
  --color-accent: #ece6d6;
  --color-accent-hover: #cfc8b5;
  --color-btn-hover: #2e373c;
  --color-btn-active: #20262a;
  --color-danger: #f2a3a0;
  --color-danger-surface: #301c1a;
  --color-danger-border: #d9706e;
  --color-warning: #e8c27a;
  --color-warning-surface: #2d2616;

  --font-sans: 'Manrope', ui-sans-serif, system-ui, sans-serif;

  --text-xs: 0.75rem;
  --text-xs--line-height: 1.5;
  --text-sm: 0.875rem;
  --text-sm--line-height: 1.43;
  --text-base: 1rem;
  --text-base--line-height: 1.5;
  --text-lg: 1.125rem;
  --text-lg--line-height: 1.38;
  --text-2xl: 1.5rem;
  --text-2xl--line-height: 1.33;
  --text-4xl: 2.25rem;
  --text-4xl--line-height: 1.2;

  --radius-field: 8px;
  --radius-card: 16px;
  --radius-pill: 9999px;
}
```

Ejemplos de uso: `bg-canvas text-fg`, `border border-line-strong rounded-pill bg-elevated hover:bg-btn-hover active:bg-btn-active`, `tabular-nums`.

## Pendiente

- **Tema claro:** se deriva de estos mismos tokens, que ya están como variables. Hasta entonces, la interfaz es solo oscura.
- **Pantallas por diseñar:** crear cuenta, iniciar sesión, resultado del pago y reserva confirmada, Mis reservas con detalle, cancelar y reprogramar, elegir negocio, y del dueño: servicios, cobro y datos del negocio.
- **Logo, banner, dirección y redes** dependen de los endpoints de datos públicos del negocio y de la subida de archivos.
