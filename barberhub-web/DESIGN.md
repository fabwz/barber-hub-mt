# BarberHub — Guía de diseño

> Consola oscura, sobria y precisa: un lienzo de grafito frío, líneas finas de 1 px y un solo acento hueso que se usa con contención. Todo es plano, sin sombras ni degradados en los componentes; solo el fondo de la página lleva un destello muy tenue en una esquina.

Este documento es la fuente de verdad visual del cliente web (`barberhub-web`: Vue 3, TypeScript y Tailwind CSS 4). Toda interfaz nueva lo sigue. Si algo no está aquí, se elige la opción más sobria y se anota.

**Temas:** oscuro y claro. La persona elige Claro, Oscuro o Automático (por defecto, sigue al dispositivo); ver «Preferencias». Los valores de esta guía son los del oscuro salvo que se diga «claro»; el claro redefine los mismos tokens (ver «Tema claro»).
**Idioma de la interfaz:** español de Costa Rica, tratando de «usted».

## Principios

1. **Una sola interfaz para todas las barberías.** No hay un color por negocio. La identidad de cada barbería viene de sus datos: logo, nombre, dirección, ciudad, teléfono, redes y un banner opcional.
2. **Casi todo es gris.** El hueso (en el tema claro, el azul marino del logotipo) es el único color de marca y solo señala foco, selección con ícono, estado «confirmada» y la página activa.
3. **Las superficies se separan con líneas de 1 px**, nunca con sombras.
4. **Cada elemento se gana su lugar.** Pocos elementos por pantalla, mucho aire, jerarquía por tamaño y peso.
5. **Ningún estado depende solo del color o del tono.** Siempre hay texto, ícono o forma. Excepción decidida: los chips de hora y de fecha se distinguen por relleno, peso y `aria-pressed`, sin check (ver su sección).

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
| `accent` | `#ece6d6` | bone | Foco, check de selección, página activa, logo |
| `accent-hover` | `#cfc8b5` | bone-hover | Enlaces al pasar el cursor |
| `btn-hover` | `#2e373c` | — | Relleno del botón tonal al pasar el cursor |
| `btn-active` | `#20262a` | — | Relleno del botón tonal presionado |
| `danger` | `#f2a3a0` | error-text | Texto e ícono de error |
| `danger-surface` | `#301c1a` | error-surface | Fondo de avisos y mensajes de error |
| `danger-border` | `#d9706e` | error-border | Borde de campo con error |
| `warning` | `#e8c27a` | warn-text | Texto, ícono y punto de advertencia |
| `warning-surface` | `#2d2616` | warn-surface | Fondo de avisos de advertencia |

El hueso (`#ece6d6`) casi no se distingue del texto (`#eef0f0`, contraste 1,1:1). Por eso **nunca se usa para resaltar texto**: marca con forma, ícono, punto, borde o línea.

### Tema claro

Mismos nombres de token y mismos roles; solo cambian los valores. Se aplica con `<html data-theme="light">` (y `color-scheme: light`); el oscuro es `data-theme="dark"`. Valores de Claude Design (lámina 19), verificados con la fórmula de luminancia relativa de WCAG 2.2.

| Token | Oscuro | Claro |
|---|---|---|
| `canvas` | `#161819` | `#f5f6f6` |
| `surface` | `#1c1f21` | `#ffffff` |
| `elevated` | `#272e32` | `#e8ebed` |
| `line` | `#2a2f32` | `#dde0e2` |
| `line-strong` | `#3a3f45` | `#c4c9cc` |
| `line-hover` | `#454b50` | `#a9afb3` |
| `field` | `#6b7073` | `#7b8185` |
| `fg` | `#eef0f0` | `#14181a` |
| `fg-muted` | `#9a9d9e` | `#4f5559` |
| `fg-subtle` | `#85888a` | `#5d6366` |
| `accent` | `#ece6d6` | `#000f2d` |
| `accent-hover` | `#cfc8b5` | `#2b3f69` |
| `btn-hover` | `#2e373c` | `#dce1e4` |
| `btn-active` | `#20262a` | `#f0f2f3` |
| `danger` | `#f2a3a0` | `#a5231f` |
| `danger-surface` | `#301c1a` | `#fdeceb` |
| `danger-border` | `#d9706e` | `#c4413a` |
| `warning` | `#e8c27a` | `#7a5300` |
| `warning-surface` | `#2d2616` | `#fbf0d6` |

- **Acento en claro:** el azul marino del logotipo, `#000f2d` (17,5:1 sobre `canvas`). Mantiene los mismos usos del hueso: foco, check, punto de selección, página activa y logo. Como el hueso en oscuro, casi no se distingue del texto (`fg`, `#14181a`), así que **tampoco se usa para resaltar texto**: marca con forma, ícono, punto, borde o línea. Su hover es `#2b3f69`.
- **Fondo de página:** el mismo destello en la esquina, con azul marino al 5 % (`rgba(0, 15, 45, 0.05)`) en lugar de hueso al 4 %. Sigue siendo el único degradado y solo vive en la capa base.
- **Logotipo:** `bh-logo-bone.png` en oscuro y `bh-logo-navy.png` (`#000F2D`) en claro (ver «Íconos e imágenes»).
- Las reglas de diseño no cambian: líneas de 1 px, sin sombras ni degradados en componentes, hueso o azul marino solo como acento.

### Contraste verificado

Texto, 4,5:1; componentes, foco y bordes de campo, 3:1 (WCAG 2.2 AA). La prueba `themeTokens.spec.ts` calcula estos pares con los valores de `main.css` en los dos temas.

| Par | Oscuro | Claro | Mínimo |
|---|---|---|---|
| `fg` sobre `canvas` | 15,6 · Cumple | 16,5 · Cumple | 4,5 |
| `fg` sobre `surface` | 14,5 · Cumple | 17,9 · Cumple | 4,5 |
| `fg` sobre `elevated` | 12,1 · Cumple | 14,9 · Cumple | 4,5 |
| `fg-muted` sobre `canvas` | 6,5 · Cumple | 7,0 · Cumple | 4,5 |
| `fg-muted` sobre `surface` | 6,1 · Cumple | 7,6 · Cumple | 4,5 |
| `fg-muted` sobre `elevated` | 5,0 · Cumple | 6,3 · Cumple | 4,5 |
| `fg-subtle` sobre `canvas` | 5,0 · Cumple | 5,6 · Cumple | 4,5 |
| `fg-subtle` sobre `surface` | 4,6 · Cumple | 6,1 · Cumple | 4,5 |
| `fg-subtle` sobre `elevated` | 3,9 · **No usar** | 5,1 · Cumple | 4,5 |
| `accent` sobre `canvas` | 14,3 · Cumple | 17,5 · Cumple | 3 |
| `accent` sobre `surface` | 13,3 · Cumple | 19,0 · Cumple | 3 |
| `accent` sobre `elevated` | 11,1 · Cumple | 15,9 · Cumple | 3 |
| `accent-hover` sobre `canvas` (texto) | 10,7 · Cumple | 9,6 · Cumple | 4,5 |
| `danger` sobre `danger-surface` | 8,1 · Cumple | 6,4 · Cumple | 4,5 |
| `warning` sobre `warning-surface` | 8,9 · Cumple | 6,0 · Cumple | 4,5 |
| `field` sobre `canvas` | 3,6 · Cumple | 3,6 · Cumple | 3 |
| `field` sobre `surface` | 3,3 · Cumple | 3,9 · Cumple | 3 |
| `field` sobre `elevated` | 2,8 · **No usar** | 3,3 · Cumple | 3 |
| `danger-border` sobre `canvas` | 5,5 · Cumple | 4,7 · Cumple | 3 |
| `danger-border` sobre `surface` | 5,1 · Cumple | 5,0 · Cumple | 3 |
| `fg` sobre `btn-hover` | 10,6 · Cumple | 13,6 · Cumple | 4,5 |
| `fg` sobre `btn-active` | 13,4 · Cumple | 15,9 · Cumple | 4,5 |
| `fg-muted` sobre `btn-hover` | 4,45 · **No usar** | 5,7 · Cumple | 4,5 |

«No usar» es la regla que ya existía en el oscuro (`fg-subtle` y campos sobre `elevated`; texto secundario sobre `btn-hover`). En claro esos pares sí pasarían, pero la regla se mantiene para que no dependa del tema.

El hueso (`#ece6d6`) casi no se distingue del texto (`#eef0f0`, contraste 1,1:1). Por eso **nunca se usa para resaltar texto**: marca con forma, ícono, punto, borde o línea. Lo mismo vale para el azul marino en claro.

Los campos van sobre `canvas` o `surface`, **nunca sobre `elevated`** (ahí `field` baja a 2,8:1).

## Tokens — Tipografía

**Familia única: Manrope**, pesos **400 y 500** (nunca 600 ni 700). Se carga solo esos dos pesos, con `font-display: swap`. No hay fuente monoespaciada: los datos (horas, precios, teléfonos, códigos) usan Manrope con cifras tabulares (`font-variant-numeric: tabular-nums`, clase `tabular-nums`).

Interletrado global: `-0.007em`. Los títulos usan `text-wrap: balance` y los párrafos `text-wrap: pretty`.

La negrita de un texto es siempre peso 500. La cursiva solo aparece en los textos legales (palabras en otro idioma, como *tenant*) y es la que el navegador sintetiza desde Manrope 400, porque no se carga una cursiva.

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
| Tarjeta | `16px` | Tarjetas, menús, fichas de método de pago, filas de servicio seleccionadas, avisos, banner de la barbería, recuadro de reserva |
| Campo | `8px` | Campos, mensajes en línea, filas de agenda seleccionadas, chips de hora |
| Círculo | `50%` | Logo, monograma, puntos |

**Líneas:** todas de **1 px**, sin excepción. No existe ningún borde de 2 px.

## Elevación y superficies

| Nivel | Token | Uso |
|---|---|---|
| 0 | `canvas` | Fondo de toda pantalla |
| 1 | `surface` | Tarjetas, menús, avisos, hover |
| 2 | `elevated` | Selección, botón tonal, página activa |

**Cero sombras en cualquier elemento**, incluidos menús, avisos y diálogos: se separan con borde `line` y la superficie del nivel. **Cero degradados en los componentes**, desenfoque o efectos de vidrio.

**Única excepción, el fondo de la página:** un degradado radial muy tenue en la esquina inferior derecha de la ventana, sobre `canvas`: `radial-gradient(120% 700px at 100% 100%, rgba(238,240,240,0.04), rgba(238,240,240,0)) fixed`. En el tema claro el color del destello es `rgba(0, 15, 45, 0.05)`: ambos viven en la variable `--page-glow`. Se declara una sola vez, en la capa base de `main.css`, y ningún componente lo usa (la prueba de reglas de diseño sigue fallando si aparece un degradado en `src/components`).

## Componentes

### Botón principal (tonal)
Píldora de `48 px` de alto en flujos de pago y `44 px` en el resto. Relleno `elevated`, texto `fg` en peso 500, 14 px, borde de 1 px `line-strong`. Hover: relleno `btn-hover`. Presionado: `btn-active`. Un solo botón principal por región visual. No existe un botón relleno de hueso. **Excepción:** en la barra de pago el texto es de 16 px, del mismo tamaño que el monto que tiene al lado.

### Botón secundario
Píldora transparente, borde de 1 px `line-strong`, texto `fg` en peso 400. Hover: borde `line-hover`.

**Botón en carga.** Vale para los dos botones y lo resuelve el componente compartido, no cada pantalla. Mientras carga queda deshabilitado, con `aria-busy` y cursor de progreso, y el texto lleva **tres puntos solo al final** («Ingresando…», «Creando cuenta…», «Reservar…»). **Nunca hay ícono ni puntos antes del texto**: tampoco se muestra el ícono propio del botón. Los puntos son decoración (`aria-hidden`); el nombre accesible es el texto sin ellos. Las pantallas escriben el texto sin puntos y el botón los agrega.

### Enlace
Texto `fg` con subrayado. Hover: `accent-hover`. Nunca se resalta con hueso en reposo.

**Navegación con apariencia de botón** («Volver», «Cambiar servicio u hora»): aunque sea un enlace, **no lleva subrayado**. Es una píldora de 44 px de alto, texto `fg` de 14 px en peso 500, con un chevron o una flecha de 16 a 18 px (trazo 1,5). Hover: relleno `surface`.

### Chip de espacio horario y de fecha
**Hora.** Botón de 44 px de alto con radio de campo (8 px), ancho mínimo de 72 px, padding horizontal de 16 px y texto de 14 px con cifras tabulares.
- **Disposición (escritorio):** agrupados por turno (Mañana, Tarde y Noche, con la etiqueta en 14 px `fg-muted`). Cada turno va en **una sola línea** con desplazamiento horizontal, sin barra visible, igual que la franja de fechas.
- **Excepción en Reprogramar (escritorio):** como usa media tarjeta ancha, los chips de cada turno y la franja de fechas **se ajustan en varias líneas** y no se desplazan hacia el lado.
- **Disposición (celular):** en lugar de chips, un `select` de 52 px (radio 8 px, borde `field`, chevron de 18 px) con la opción inicial «Elija una hora». Los espacios ocupados salen deshabilitados con el sufijo «ocupada» y, si hay alguno, una nota de 14 px en `fg-subtle`: «Las horas marcadas como ocupadas no se pueden elegir.»
- **Con «Sin preferencia»:** cada chip lleva debajo, en 12 px `fg-muted`, «con {barbero}» (una sola vez por hora).
- **Libre:** transparente, borde 1 px `line-strong`, texto `fg`. Hover: borde `line-hover`.
- **Seleccionado:** relleno `elevated`, **el mismo borde** 1 px `line-strong` y texto en peso 500. **Sin check:** se decidió omitir el check que pedía la versión anterior de esta guía. El estado se anuncia con `aria-pressed="true"`.
- **Ocupado:** borde `line`, texto `fg-subtle` tachado, `aria-disabled="true"` y cursor `not-allowed`. No es seleccionable.
- **Foco de teclado:** contorno de 1 px `accent` con 2 px de separación.

**Día.** Píldora de 56 × 72 px con el día de la semana abreviado (14 px) y el número (18 px, tabulares), en una franja con desplazamiento horizontal sin barra visible y 8 px entre píldoras. Sobre la franja, el título «Fecha» y el mes («noviembre 2026», 14 px `fg-subtle`) a la derecha. Teclado: las flechas, Inicio y Fin mueven el foco entre días.
- **Libre:** transparente, borde `line-strong`, texto `fg`. Hover: borde `line-hover`.
- **Seleccionado:** relleno `elevated`, borde `line-strong` y el número en peso 500, sin check.
- **Sin horas:** borde `line`, texto `fg-subtle`, «–» como número, `aria-disabled="true"` y cursor `not-allowed`. Se anuncia como «sin horas» a los lectores de pantalla (`aria-label` «dom 8 nov, sin horas»).
- **Celular, «Otra fecha»:** bajo el título, una fila con el mes y el botón «Otra fecha» (chevron, 44 px de alto) que despliega el calendario del mes en una tarjeta `surface` de 16 px con borde `line`: encabezado «lun … dom», celdas de 44 px (píldora), el elegido con relleno `elevated` y peso 500, los días sin horas en `fg-muted`, y la nota «Los días con guion no tienen horas disponibles.»

### Fila de servicio (lista de selección)
Filas planas de **64 px** de alto (padding de 8 px arriba y abajo y 16 px a los lados). Cada fila muestra nombre, duración y precio (en la portada, con el IVA incluido y la base desglosados). La lista sobresale 16 px a cada lado de su columna para que el texto quede alineado con el resto del contenido.
- **En reposo:** sin radio, separadas por una línea `line` de 1 px que **no llega a los bordes**: empieza y termina 16 px antes de cada lado.
- **Hover:** relleno `surface` con radio de 16 px y sin línea.
- **Seleccionada:** relleno `elevated`, radio de 16 px, sin línea, **solo el nombre** en peso 500 (duración y precio siguen en 400) y un indicador de radio de 22 px a la derecha: círculo de 1 px `line-strong` con un punto de 10 px en `accent`. Sin seleccionar, el círculo lleva borde `field`.
- Es un grupo `role="radiogroup"` con opciones `role="radio"`.

### Ficha de método de pago
Tarjeta de 16 px con borde 1 px `line-strong`, ícono de 20 px, nombre y una línea de descripción. Hover: borde `line-hover`. Seleccionada: relleno `elevated` con el mismo borde. Mismo patrón de `radiogroup`.
- **Mosaico (pantalla de pago):** las fichas van en una fila de tres columnas con 8 px entre ellas. Cada una mide al menos 76 px de alto, tiene 8 px arriba y abajo y 6 px a los lados, y lleva el ícono de 22 px centrado **sobre** el nombre (14 px, centrado), sin indicador de radio ni descripción. Seleccionada: relleno `elevated`, nombre en peso 500 e ícono en `accent`. Bajo el grupo, una nota de 14 px en `fg-subtle` que explica el método elegido.
- **Qué fichas salen:** las que admite la pasarela de la barbería, que cobra con una sola (PA-02). Con ONVO: «Tarjeta» y «SINPE Móvil». Con PayPal: una sola ficha, **«PayPal»** (la tarjeta se escribe en la página de PayPal), y bajo ella un bloque con líneas `line` arriba y abajo: «Pago con PayPal» en peso 500 y la nota «Al pagar, se abre PayPal para que confirme el pago.» Si hay una sola ficha, queda elegida.

### Campo de texto
Alto de 48 px, radio de 8 px, relleno `canvas`, borde de 1 px `field`, texto `fg`, marcador `fg-subtle`. La etiqueta va arriba, en 14 px y peso 500.
- **Foco:** el borde pasa a `accent` de 1 px, sin halo ni sombra, y se quita el contorno.
- **Error:** borde `danger-border` y debajo un mensaje con ícono de 16 px, en `danger`, que nombra el problema y la salida. Se asocia con `aria-describedby` y `aria-invalid`.
- **Deshabilitado:** borde `line` y texto `fg-subtle`.
- **Contraseña:** un botón con ícono de ojo (`Eye` / `EyeOff`, 20 px) de 48 × 48 px en el borde derecho del campo, con `aria-label="Mostrar contraseña"` y `aria-pressed`. Muestra u oculta lo escrito y se deshabilita mientras se envía. Está en crear cuenta y en iniciar sesión.
- **Correo:** si falta algo, el mensaje dice qué (el `@`, lo que va antes del `@`, el dominio o los espacios) y da un ejemplo («nombre@correo.cr»). Se acepta cualquier terminación.
- **Nombre:** no admite números ni símbolos; sí letras con tilde, espacios, apóstrofo, guion y punto.
- **Teléfono:** solo dígitos, como máximo 8, con el guion puesto solo al escribir el quinto (`1234-5678`, también en el marcador). Teclado numérico. A la API se envían solo los dígitos.
- **Todo campo filtra al escribir:** la tecla que no corresponde **no aparece**. No basta con limpiar el valor guardado: el campo debe mostrar el valor limpio en cada pulsación (si el valor limpio coincide con el anterior, el navegador no lo redibuja). Cada campo admite solo la cantidad que necesita.
- **Número de tarjeta:** solo dígitos, hasta 19, en grupos de cuatro («1234 5678 9012 3456»). **Vencimiento:** solo dígitos con la barra puesta sola (`MM/AA`). **CVV** (esa es la etiqueta): de 3 a 4 dígitos. **Nombre en la tarjeta:** sin números ni símbolos (letras con tilde, espacios, apóstrofo, punto y guion), hasta 40 caracteres. Los tres primeros con teclado numérico y `autocomplete` `cc-number`, `cc-exp`, `cc-csc` y `cc-name`.
- **Teléfono del SINPE Móvil:** igual que el teléfono de arriba. Si el navegador lo completa con «+506», se lo quita. A la API se envía `+506` y los 8 dígitos.
- **Identificación del SINPE Móvil:** queda deshabilitada («Elija primero el tipo») hasta elegir el tipo de identificación, y se adapta a él. Al cambiar el tipo se vuelve a dar formato a lo escrito. Los formatos son los habituales de Costa Rica y **están por confirmar con ONVO**:

| Tipo | Qué admite | Ejemplo |
|---|---|---|
| Nacional | 9 o 10 dígitos con guiones | `1-1393-1919` o `01-1393-1919` |
| Residente | hasta 12 dígitos | `155812345678` |
| Entidad estatal, jurídica, institución autónoma | 10 dígitos con guiones | `3-101-123456` |
| Diplomático, extranjero | letras y números, en mayúscula, hasta 20 | `AB1234567` |

### Casilla
Cuadro de 20 px con radio de 8 px y borde de 1 px, dentro de una fila de al menos 44 px de alto con 12 px de separación hacia su texto. Es un `button` con `role="checkbox"`, `aria-checked`, `aria-required` cuando es obligatoria y `aria-labelledby` hacia su texto.
- **Sin marcar:** transparente con borde `field`.
- **Marcada:** relleno `elevated`, borde `line-strong` y un check de 14 px en `accent`. El check es obligatorio: el tono por sí solo no identifica el estado.
- **Error:** borde `danger-border`, con un mensaje debajo propio de cada casilla («Debe aceptar los Términos y condiciones para crear su cuenta.»).
- **Texto:** las palabras de apoyo («Acepto los», «Acepto la») van en `fg-muted` y peso 400. Solo el nombre del documento va en peso 500, sin subrayado, y es un enlace a su página en la misma pestaña; un clic en el enlace no marca la casilla.
- **Enviando:** borde `line` y opacidad de 0,6.

### Lista de requisitos de la contraseña
Aparece bajo el campo de contraseña, dentro de una región `aria-live="polite"`. Es **una sola frase** de ayuda, en 14 px y `fg-subtle`, que nombra todos los requisitos («Mínimo 8 caracteres, una mayúscula, una minúscula, un número y un símbolo.»). No cambia mientras la persona escribe.
- **Tras enviar con algo pendiente:** la frase pasa a `danger` en peso 500, junto al mensaje de error del campo (que lleva ícono y texto, así que el estado no depende solo del color).
- **Al cumplir todo:** la frase vuelve a `fg-subtle`. Si todo estaba bien, no pasa nada.
- **Lectores de pantalla:** tras un envío fallido se anuncia cada requisito con texto oculto, «Cumplido: » o «No cumplido: », seguido del requisito.

Los requisitos concretos salen del contrato y de las reglas del negocio (SE-07: 8 caracteres, con mayúscula, minúscula, número y símbolo).

En «Mi cuenta · Seguridad» y en el cambio obligatorio la lista es **una por requisito** (ver «Lista de requisitos con estado», en «Mi cuenta»).

### Aviso en línea informativo
Una fila con un ícono de información de 16 px y texto, sin color de estado, dentro de una tarjeta de 16 px con borde de 1 px `line` y relleno `surface`. Se usa para indicar que un contenido es de ejemplo. La primera frase va en peso 500 y la segunda en `fg-muted`.

### Etiqueta de estado
Solo el texto, sin punto ni relleno, en 14 px y peso 500 (`fg-muted` en «Expirada» y «Reembolsado»). Así el estado nunca depende del color ni del tono, porque lo dice la palabra. **Es la misma etiqueta en todas las pantallas** (Mis reservas, detalle, confirmación, pago, agenda y bonos): un único componente, sin variantes. En el detalle de una reserva va bajo el título, a la izquierda, y no a la derecha. Ninguna etiqueta lleva punto de color (ni el amarillo de «Pago pendiente»).

| Estado de la reserva | Texto | Texto en |
|---|---|---|
| Temporal | Pendiente de pago | `fg` |
| Confirmada | Confirmada | `fg` |
| Completada | Atendida | `fg` |
| Ausente | Ausente | `fg` |
| Cancelada (por el cliente o por la barbería) | Cancelada | `fg` |
| Expirada | Expirada | `fg-muted` |

| Estado del pago | Texto | Texto en |
|---|---|---|
| Pendiente | Pago pendiente | `fg` |
| Aprobado | Pago aprobado | `fg` |
| Rechazado | Pago rechazado | `fg` |
| Fallido | Pago fallido | `fg` |
| Reembolsado | Reembolsado | `fg-muted` |

| Estado del bono | Texto | Texto en |
|---|---|---|
| Activo | Activo | `fg` |
| Vencido | Vencido | `fg-muted` |

| Estado del servicio | Texto | Texto en |
|---|---|---|
| Activo | Activo | `fg` |
| Inactivo | Inactivo | `fg-muted` |

La interfaz usa estos textos y no «Pagado», que no es un estado del sistema. El diseño de los bonos dibuja un punto (relleno hueso o hueco) junto a «Activo» y «Vencido»: se omite, porque esta etiqueta es una sola y no lleva punto; la palabra ya dice el estado.

**Dónde no se muestra:** en la pantalla de pago no se muestra «Pago aprobado», porque el aviso «Su pago de ₡X fue aprobado.» ya lo dice. Tampoco «Pendiente de pago» en el resumen de una reserva temporal: lo dice la cuenta regresiva. «Pago pendiente», «Pago rechazado» y «Pago fallido» sí se muestran.

### Fila de agenda
Plana, sin tarjeta. Columnas: rango de hora (tabular), cliente, servicio (y, para el dueño, el barbero) y etiqueta de estado. Separadas por líneas de 1 px `line`. Seleccionada: relleno `elevated` con radio de 8 px, sin borde, texto en peso 500. En la vista por día, la **hora actual** se marca con un punto de 9 px y una línea de 1 px, ambos en `accent`, entre las reservas que ya empezaron y las que no; solo en la fecha de hoy.

### Barra del personal
Diseño de origen: lámina 26 de la v7. Es la barra del dueño y del colaborador (64 px, línea inferior de 1 px `line`, mismas medidas que el navbar del cliente). A la izquierda, la marca (enlace a la agenda) y los enlaces; a la derecha, la barbería activa y el menú de cuenta.
- **Enlaces:** «Agenda» (los dos roles) y, solo para el dueño, «Servicios», «Equipo», «Bonos», «Ingresos» y «Configuración». Un enlace aparece **solo cuando su ruta existe** (`router.hasRoute`), como en el navbar del cliente: hoy solo «Agenda». El activo lleva relleno `elevated`, texto `fg` en peso 500 y `aria-current="page"`; la reserva abierta en el panel mantiene «Agenda» como activa.
- **Barbería activa:** nombre (14 px, peso 500, una línea) y, debajo, el rol (12 px, `fg-muted`). Con más de una barbería, junto al rol va el enlace subrayado «Cambiar de barbería», que lleva al selector (`/negocios`). La lámina 26b dibuja un desplegable; se usa el selector que ya existe.
- **Menú de cuenta:** el mismo `AccountMenu`, con la fila «Mi cuenta» (a `/cuenta/datos`) antes de «Cerrar sesión». En celular, los enlaces de la barra van primero en el panel.
- **Qué barra usa «Mi cuenta» (`/cuenta/*`):** la del personal cuando hay una barbería activa (el id guardado confirmado por `GET /mis-barberias`); si no, la del cliente. Un cliente sin barbería guardada no pide la lista.

### Navbar del cliente
Barra de las pantallas de la persona con sesión iniciada (hoy, solo el directorio de `/barberias`). Es la misma barra del negocio: 64 px de alto, línea inferior de 1 px `line`, 20 px de relleno lateral en celular y 32 px en escritorio, y 40 px entre la marca y los enlaces. **No reemplaza la cabecera de cada barbería** y no lleva «Mis reservas»: esa lista es por barbería y vive en su cabecera.
- **Escritorio:** la marca (`BrandLogo` de 45 px, enlace al directorio), los enlaces en píldora de 44 px y, a la derecha, el menú de cuenta. Enlace en reposo: texto `fg-muted`; hover: relleno `surface`. **Activo:** relleno `elevated`, texto `fg` en peso 500 y `aria-current="page"`.
- **Enlaces:** «Barberías», «Mi perfil» (la sección Datos de «Mi cuenta», `/cuenta/datos`) y «Preferencias» (su sección Apariencia, `/cuenta/apariencia`). Un enlace aparece **solo cuando su ruta existe** (`router.hasRoute`), así que la barra nunca apunta a una pantalla que no está. Dentro de «Mi cuenta» siempre hay un solo enlace marcado como página activa (`aria-current`, relleno `elevated`): «Mi perfil» en Datos **y en Seguridad**, y «Preferencias» en Apariencia.
- **Celular:** la marca y un botón de menú de 44 px (`aria-label="Menú"`); el panel ocupa el ancho de la pantalla (12 px de margen) y lleva los enlaces en filas de 48 px, el nombre y «Cerrar sesión».
- Transiciones de 150 ms `ease-out` solo en hover y en la apertura del menú (con la reducción de movimiento, ninguna).

### Menú de cuenta
- **Botón (escritorio):** píldora de 44 px de alto con el monograma de 32 px (`elevated`, borde `line-strong`, 12 px en peso 500), el nombre (14 px, peso 500, con puntos suspensivos si no cabe) y un chevron de 18 px que **gira 180°** al abrir. Cerrado: transparente con borde `line-strong`. Hover: relleno `surface` y borde `line-hover`. Abierto: relleno `elevated` y borde `line-hover`. Tiene `aria-expanded` y `aria-controls`; no usa `role="menu"` (es una lista de enlaces y un botón).
- **Botón (celular):** círculo de 44 px con el ícono de menú de 20 px (`Menu`), que pasa a `X` al abrir. Mismos colores de borde y relleno.
- **Panel:** 248 px de ancho en escritorio (alineado a la derecha, 6 px bajo la barra), radio de 16 px, relleno `surface`, borde `line`, 8 px de relleno y **sin sombra**. Arriba, «Sesión iniciada» (12 px, `fg-muted`) y el nombre completo (14 px, peso 500); una línea `line` de 1 px; y una fila de 44 px (48 px en celular), «Cerrar sesión», con ícono `LogOut` de 18 px en `fg-muted`; hover `elevated`. En celular, antes de «Sesión iniciada», van los enlaces de la barra, separados del bloque de la persona por una línea.
- **Teclado:** `Escape` lo cierra y devuelve el foco al botón; un clic fuera también lo cierra, y también seguir un enlace. «Cerrar sesión» invalida el token y lleva a `/`.
- **Nombre:** la sesión solo conoce el correo, así que mientras la API no entregue el nombre de la cuenta se muestra el correo, y el monograma sale de lo que va antes de la «@» (`laura.brenes@…` es «LB»).

### Tarjeta de barbería
Enlace completo a `/b/:enlace`, con borde de 1 px `line`, radio de 16 px, relleno de 16 px y **sin sombra**. De izquierda a derecha: un círculo de 48 px con el logo recortado o, si no hay, el **monograma** (iniciales de las dos primeras palabras de más de dos letras del nombre, 14 px, peso 500, relleno `elevated`, borde `line-strong`); el nombre (peso 500, una sola línea con puntos suspensivos) y la ciudad (14 px, `fg-muted`); y un chevron de 18 px en `fg-subtle`. Sin calificaciones ni etiquetas.
- **Reposo:** transparente. **Hover:** relleno `surface` y borde `line-strong`. **Presionada:** relleno `elevated`. **Foco de teclado:** el contorno común de 1 px `accent` con 2 px de separación.
- **Dirección:** el diseño la trae como tercera línea (14 px, `fg-subtle`, una línea con puntos suspensivos), pero la API del directorio no la entrega: **no se muestra** hasta que lo haga.
- **Disposición:** celular en una columna con 12 px de separación; escritorio en tres columnas con 16 px, dentro de un máximo de 1040 px centrado.

### Campo de búsqueda
El campo de texto de arriba (48 px de alto, radio de 8 px, borde `field`, relleno `canvas`) con una lupa de 20 px a la izquierda (`fg-muted`) y, **solo cuando hay texto**, un botón «Borrar búsqueda» de 44 px a la derecha, que vacía el campo y deja el foco en él. Foco: borde `accent`. Deshabilitado mientras carga por primera vez: opacidad de 0,6 (en las búsquedas siguientes **no** se deshabilita, para no perder el foco al escribir). Va dentro de `role="search"`, el campo es `role="searchbox"` y su nombre es «Buscar por nombre o ciudad». En escritorio mide como máximo 480 px. Busca por nombre o ciudad sin distinguir tildes ni mayúsculas (lo resuelve la API) y espera 300 ms tras la última tecla.

### Directorio de barberías
Ruta `/barberias`, con sesión. Diseño de origen: sección 11 de la v7. Título «Barberías» (24 px, peso 400) y la línea «Elija una barbería para ver sus servicios y reservar su cita.»; bajo ella, el campo de búsqueda y la lista de tarjetas. Los textos de los estados:
- **Cargando:** esqueleto de cuatro tarjetas en celular y seis en escritorio (círculo de 48 px y dos líneas), con `aria-busy` y sin animación con `prefers-reduced-motion`.
- **Resultado de la búsqueda:** una línea de estado (`role="status"`, 14 px, `fg-muted`) con el total de `meta` («1 barbería», «2 barberías»), solo mientras hay una búsqueda.
- **Vacío general:** «Aún no hay barberías disponibles.» y «Cuando haya una disponible, aparece aquí.», entre dos líneas `line`, sin campo de búsqueda. No lleva acción: no hay nada que hacer.
- **Vacío de búsqueda:** «No encontramos barberías con “texto”.», «Revise el nombre o la ciudad, o borre la búsqueda para ver todas.» y el botón secundario «Borrar búsqueda».
- **Error:** caja de 8 px de radio con relleno `danger-surface` y 16 px de relleno: ícono de 20 px, «No pudimos cargar las barberías.» en `danger` y peso 500, «Revise su conexión e intente de nuevo.» y el botón secundario «Reintentar». Si había una búsqueda, el campo se mantiene.
- **Más páginas:** el diseño no tiene paginación. Se pide el mayor `por_pagina` que admite la API (50) y, si `meta` indica más páginas, bajo la lista sale el botón secundario «Ver más», que agrega la página siguiente; si falla, «No pudimos cargar más barberías. Intente de nuevo.» en `danger`, sin perder la lista.

### Aviso
Tarjeta de 16 px con borde de 1 px, sin sombra. Aparece abajo a la derecha en escritorio y arriba en el celular.
- **Éxito:** relleno `surface`, borde `line`, check en `accent` y texto `fg`. `role="status"`.
- **Error:** relleno `danger-surface`, **sin borde**, ícono y texto `danger`. `role="alert"`. Ningún panel de error lleva borde (avisos, errores de carga y errores de pago): lo identifican el relleno, el ícono y las palabras. El borde `danger-border` queda solo para campos y casillas con error.
Los textos nombran el problema y la salida, sin «Error:» doble ni signos de exclamación.

### Esqueleto de carga
Bloques de `line` con radio de 8 px que imitan la forma del contenido (fila de servicio, bloque de agenda). Nunca ruedas. La animación es un pulso lento de opacidad y se quita con la reducción de movimiento (ver «Movimiento y foco»). El contenedor lleva `aria-busy="true"`.

### Estado vacío y estado de error
- **Vacío:** un título que nombra el espacio, una línea que explica qué pasa y una acción en verbo. Ejemplo: «No hay reservas para el sáb 7 nov.» y «Ir a hoy».
- **Error de carga:** «No pudimos cargar …», sin el prefijo «Error:» (es el texto de la v7), una línea con la salida («Revise su conexión e intente de nuevo. Lo que ya eligió se mantiene.») y el botón secundario «Reintentar». Lo ya elegido por la persona nunca se pierde. Es una sola caja para todas las pantallas (componente `ErrorState`): radio de 8 px, relleno `danger-surface`, sin borde, 16 px de relleno, ícono de 20 px y título en `danger` con peso 500. Si la API pide una espera (429), el botón queda deshabilitado y una línea dice cuánto falta.
- **Selector de negocio sin negocios** (`/negocios`): cuando la cuenta no tiene barberías (por ejemplo, un cliente nuevo) no se redirige; se muestra «Aún no tiene barberías.», la línea «Explore las barberías para reservar su primera cita.» y el enlace «Ver barberías» hacia el directorio. El diseño de la v7 no define esta pantalla: sigue el patrón del estado vacío.

### Cabecera de la barbería (pantallas del cliente)
- **Banner opcional:** proporción 2:1 en celular y 3:1 en escritorio (4:1 en la portada de 1280 px), con **radio de 16 px**, decorativo (`aria-hidden`). Queda separado de la ventana con el mismo margen de los lados: 20 px en celular y 32 px en escritorio, también arriba. No lleva texto encima. Mientras el negocio no tenga imagen se muestra un bloque neutro `line`.
- **Logo:** círculo de 72 px (88 px en escritorio con banner; 56 px sin banner) con borde de 1 px `line-strong`, posado sobre el borde inferior del banner, con su centro justo en ese borde. **El banner se recorta alrededor del logo** con un hueco de 6 px, de modo que se ve el fondo de la página tal cual (con su resplandor): es una máscara de borde duro, no un anillo pintado, y por eso solo existe en la mitad que toca el banner. Sin logo, un **monograma** con las iniciales de las dos primeras palabras de más de dos letras del nombre, sobre `elevated`. Mientras no haya imagen, el marco muestra un ícono de cuadro con una colina (24 px, trazo 1,5, `fg-subtle`).
- **Sin banner:** cabecera compacta, con el logo a la izquierda del nombre en celular.
- **Escritorio:** la cabecera va centrada y a todo el ancho de la página: logo, nombre y, debajo, una sola fila centrada (máximo 920 px) con el teléfono, la dirección y las redes. El espacio entre el teléfono y la dirección es igual al que hay entre la dirección y las redes. La línea inferior de la cabecera llega de borde a borde del contenedor de 1280 px.
- **Nombre:** 36 px, peso 400, balanceado. Es el que entrega el negocio, sin agregar ni quitar palabras (si incluye «Barbería», es parte de su nombre).
- **Ubicación:** un botón en píldora con ícono de pin, la dirección con la ciudad y un chevron. Despliega una lista de enlaces dentro de una tarjeta de 16 px con borde `line` (en escritorio, flotante, de 280 px y centrada bajo el botón): «Ver en Google Maps», «Cómo llegar» y «Abrir en Waze». Cada opción lleva a la derecha su propio ícono de 16 px en `fg-muted`, no una flecha de salida: el logo de Google Maps, una flecha de navegación (Lucide `Navigation`) y el logo de Waze. Las líneas entre opciones son de 1 px `line` y **no tocan el borde de la tarjeta**: empiezan y terminan 16 px antes. Los enlaces se arman con la dirección en texto (`https://www.google.com/maps/search/?api=1&query=…`, `https://www.google.com/maps/dir/?api=1&destination=…` y `https://waze.com/ul?q=…&navigate=yes`) y abren en una pestaña nueva con `rel="noopener"`. Se implementa como un botón con `aria-expanded` que despliega una lista de enlaces, **sin** `role="menu"`.
- **Teléfono:** enlace `tel:`, 14 px, cifras tabulares.
- **Redes y correo:** como máximo el correo, WhatsApp, Instagram, Facebook y TikTok, **en ese orden**; el correo es un enlace `mailto:` con el correo de contacto del negocio (distinto del correo de la cuenta del dueño). Los íconos son de **18 px**: los de las redes son los glifos oficiales rellenos (ver «Íconos e imágenes») y el del correo es de Lucide. Cada uno conserva un objetivo de 44 px, pero los objetivos se traslapan 8 px para que los íconos queden más juntos. **Solo se muestran los que el dueño configuró** al editar los datos de su negocio; no hay enlaces vacíos.
- Se prueban cuatro casos: con logo y banner, solo con logo, sin ninguno de los dos, y con un banner oscuro y de baja calidad.

### Panel de reserva (portada de la barbería)
Se muestra bajo la cabecera, en la misma pantalla (`/b/:enlace`). En escritorio es un único recuadro de 16 px con borde `line`, 32 px de padding y 32 px de separación respecto de la cabecera; en celular no hay recuadro y todo va en una columna.
- **Título:** «Reservar cita» (24 px, peso 400) con una línea `line` debajo en escritorio.
- **Volver:** arriba de la pantalla, antes de la cabecera, un botón «Volver» (navegación con apariencia de botón: píldora de 44 px con chevron, sin subrayado, foco visible) que lleva al directorio «Barberías» (`/barberias`). Se muestra en todos los estados de la pantalla (cargando, error, no existe y lista) y es el mismo componente `BackLink`. Es un enlace al directorio, no «atrás» del navegador: si se llegó por el enlace directo de la barbería, igual lleva al directorio.
- **Dos columnas iguales** separadas por una línea de 1 px y 32 px de espacio a cada lado. Izquierda: «Servicio» (la fila de servicio de arriba) y «Barbero». Derecha: «Fecha» y «Hora». En celular el orden es el mismo, apilado. Los títulos de paso son de 18 px, peso 500.
- **Barbero:** un `select` de 52 px, radio de 8 px, borde `field`, texto de 16 px en peso 500 y chevron de 18 px. La primera opción es «Sin preferencia».
- **Sin servicio elegido:** la columna derecha muestra un círculo de 56 px (borde `line-strong`, relleno `surface`) con un ícono de calendario de 24 px, el título «Elija un servicio» (18 px, peso 500) y una línea de 14 px en `fg-muted`.
- **Cargando horas:** 8 píldoras de esqueleto de 72 × 44 px.
- **Error al cargar horas:** caja de 8 px de radio con relleno `danger-surface` y 16 px de padding: ícono de 20 px en `danger`, el título «No pudimos cargar los horarios.» en `danger` y peso 500, la línea «Revise su conexión e intente de nuevo. Lo que ya eligió se mantiene.» y el botón secundario «Reintentar».
- **Sin horas ese día:** un recuadro con líneas `line` arriba y abajo, el título «No quedan horas el vie 6 nov con Mauricio.», una línea que sugiere probar otro día, botones secundarios «Ver sáb 7 nov» (los días siguientes con horas) y un botón de texto «Sin preferencia» con flecha.
- **Barra inferior:** una fila con una línea `line` encima. A la izquierda el resumen (16 px): el servicio en peso 500 y, en `fg-muted`, la fecha y la hora con el barbero («sáb 7 nov, 08:15 con Mauricio»), con un espacio entre la hora y «con». Sin hora elegida dice «Elija una hora para continuar» (o «Elija un servicio para continuar»). A la derecha, el total (18 px, tabulares) con «IVA incluido» y el botón principal «Reservar» de 48 px y 280 px de ancho. Deshabilitado: borde `line`, relleno `surface` y texto `fg-muted`, sin opacidad. En celular la barra queda fija al pie de la pantalla, con una línea superior, el resumen dentro de una tarjeta `surface` y el botón a todo el ancho.
- **Reserva creada:** la lista de servicios y el barbero **desaparecen**: ya hay una reserva y no tiene sentido seguir eligiendo. «Su reserva» queda en **una sola columna de 440 px centrada** en el recuadro, con el resumen (la tarjeta de «Resumen y pago»), la cuenta regresiva de 10 minutos («Su espacio se libera en 9:58», sin etiqueta de estado) y dos botones de 48 px: el principal «Continuar al pago» y el secundario **«Editar reserva»**, que vuelve a la selección para cambiar servicio, barbero, día u hora. **Cancela la reserva temporal** antes de volver (RF-16, RE-04), para que su espacio quede libre y no choque con el mismo horario; si la cancelación falla, vuelve igual, porque la reserva vence sola. Con un pago en proceso no se ofrece: en su lugar sale el aviso «Tiene un pago en proceso. Continúe al pago para ver su estado.», porque cancelar dejaría un cobro sin reserva. Si vence, un aviso «La reserva temporal venció.» y el botón «Elegir otro horario».
- **Espacio ocupado (409):** bajo la hora, «Otros espacios cercanos» como chips con los espacios que devuelve la API; al elegir uno se reemplaza solo la hora.

### Bloque «Describa su cita» (reserva con IA)
Diseño de origen: sección 12 de la v7 («Reservar con IA») y su bloque «Componentes nuevos». Panel plegable al inicio de «Reservar cita», **encima** de los selectores, que se conservan sin cambios y quedan separados por una línea con el texto «O elija a mano». No es un chat: una frase, una interpretación y una propuesta que la persona revisa; sin burbujas ni historial. Solo existe mientras no hay una reserva creada.
- **Recuadro:** borde de 1 px `line`, radio de 16 px, sin fondo ni sombra. Relleno de 8 px arriba y 16 px a los lados y abajo en celular; 20 px arriba, 24 px a los lados y abajo en escritorio.
- **Cabecera:** un botón de 44 px con el título «Describa su cita» (18 px, peso 500), la línea «Escriba en una frase qué desea y generamos una propuesta.» en 14 px `fg-muted` y un chevron de 18 px que **gira 180°** al abrir (150 ms `ease-out`). Lleva `aria-expanded` y `aria-controls`.
- **Apertura:** en celular parte plegado, para que el servicio siga a la vista; en escritorio parte abierto, en dos columnas iguales con 32 px entre ellas (la frase a la izquierda, la propuesta a la derecha). Se abre al generar una propuesta y cuando ya hay una frase o una propuesta (por ejemplo, al volver del acceso). El diseño pide abrirlo también cuando la persona «entra pidiendo reservar con IA», pero no define esa entrada: no existe todavía.
- **Campo de frase:** el campo de texto en versión de varias líneas: radio de 8 px, borde `field`, relleno `canvas`, 3 filas (96 px en celular, 112 px en escritorio), sin redimensionar, relleno de 12 px y **como máximo 300 caracteres** (el límite del contrato; el diseño decía 200). Foco: el borde pasa a `accent`. Mientras interpreta queda deshabilitado con opacidad de 0,6. Debajo, la nota «Se interpreta con inteligencia artificial. Nada se reserva hasta que usted continúe.» en 14 px `fg-subtle`.
- **Botón «Generar propuesta»:** el botón principal de 48 px (200 px en escritorio, ancho completo en celular). Deshabilitado mientras el campo está vacío (borde `line`, relleno `surface`, texto `fg-muted`, sin opacidad); mientras la IA responde dice «Generando propuesta…» y también queda deshabilitado.
- **Reposo (escritorio):** junto al campo, «Puede escribir, por ejemplo:» y tres frases de ejemplo en 14 px `fg-muted`. En celular no se muestran.
- **Interpretando:** «Procesando su solicitud…» (14 px `fg-muted`) y esqueletos con pulso (dos líneas y tres chips de 72 × 44 px), sin animación con `prefers-reduced-motion`. El bloque lleva `aria-busy` y una región `aria-live="polite"` anuncia «Generando propuesta.».
- **Propuesta:** texto plano en la columna de resultados. «Solicitud» en 14 px `fg-muted` y debajo la frase interpretada en peso 500, armada solo con lo que la API resolvió («Corte + barba con Mauricio, el jue 5 nov, por la tarde.»; sin servicio resuelto dice «Una cita…»). Con eso se rellenan los selectores de abajo (servicio, barbero y día) por la tienda de la reserva. Después, «Espacios disponibles — jue 5 nov con Mauricio» con **chips de hora** de 44 px, radio de 8 px y 4 columnas (los mismos de Reservar, también en celular); **ninguno viene elegido** y al elegir uno se completa la hora de abajo (`aria-pressed`). Con varios barberos libres a esa hora, cada chip dice «con {barbero}». La nota «Al elegir una hora se completa abajo. Revise y reserve para continuar al pago.» va en 14 px `fg-subtle`. **La propuesta no reserva nada:** se reserva con el «Reservar» de siempre.
- **Aviso de advertencia:** radio de 8 px, relleno `warning-surface`, ícono de 20 px y título en `warning` (peso 500), explicación en 14 px `fg-muted`. Se usa para datos faltantes («Faltan datos»), varias coincidencias («Hay varias opciones»), servicios o barberos que la barbería no tiene («No disponible en esta barbería») y espacios ocupados con alternativas. No inventa ningún dato: lo que no se pudo resolver no se rellena.
- **Dato sin elegir:** abajo, en el selector que la propuesta dejó abierto, el título lleva a la derecha «Falta elegir» en 14 px `warning`; el borde del barbero pasa a `warning` y, en el servicio, la línea superior de la lista. La marca se quita al elegir ese dato. El día lleva solo el texto.
- **Sin espacios:** el recuadro con líneas `line` arriba y abajo, «No quedan espacios el sáb 7 nov con Andrés.» y la salida («Pruebe otro día, o elija «Sin preferencia» abajo…»). Los botones «Ver otro día» y «Sin preferencia» son los de «Sin horas ese día» de abajo, que ya muestra el panel.
- **Error del proveedor** (falla, respuesta inválida o tiempo agotado: 503 `servicio-no-disponible`; también 429 `demasiadas-solicitudes` y fallos de red): el aviso rojo de siempre (`role="alert"`) con «No pudimos procesar su solicitud.», «Puede continuar a mano: elija el servicio, el barbero, el día y la hora aquí abajo. Su frase se conserva.» y el botón secundario «Reintentar». Con `Retry-After` suma «Podrá intentar de nuevo en N s.» y el botón queda deshabilitado hasta que pase.
- **Sin sesión:** «Generar propuesta» lleva al acceso igual que «Reservar»; la frase se conserva en memoria (nunca en el almacenamiento) y está al volver.
- **Privacidad:** a la API solo viaja la frase; nunca el correo ni el teléfono de la persona (IA-06).

## Resumen y pago (pantalla del cliente)

Ruta `/b/:enlace/pago/:reserva`. Diseño de origen: 6n a 6q (celular) y 9a a 9d (escritorio). Todo lo que sale de la API se muestra tal cual: los montos (`monto_a_pagar`, `saldo_en_local`) nunca se calculan en el cliente.

- **Celular (una columna):** encabezado ligero con «Volver» a la izquierda y el nombre de la barbería a la derecha; el título «Resumen y pago» (24 px, peso 400); el resumen; «Cambiar servicio u hora →» (solo mientras la reserva es temporal, sin pago y en plazo; cancela la reserva como «Editar reserva») y la nota del plazo; «Método de pago» con las fichas en mosaico, su nota y el formulario; y una barra fija al pie, con una línea superior, la nota de la pasarela centrada («Pago seguro con ONVO Pay» o «Pago protegido por PayPal») y el botón de 48 px a todo el ancho.
- **Escritorio (1280 px):** la misma cabecera de la barbería de la portada y, debajo, un recuadro de 16 px con borde `line` y 32 px de padding. Una fila superior con el título y «Volver» a la derecha, con una línea `line` debajo. Dos columnas iguales separadas por una línea de 1 px y 32 px de espacio a cada lado: a la izquierda «Su cita» (18 px, peso 500) y a la derecha «Método de pago». Cierra con una línea `line` y una fila: a la izquierda un candado de 16 px y la nota de la pasarela; en medio «A pagar ahora ₡X» (o «Total» si se cobra todo) en 16 px; y a la derecha el botón principal de 280 px.
- **Resumen:** tarjeta `surface` con borde `line`, radio de 16 px y 20 px de padding a los lados. Filas Servicio, Barbero, Fecha, Hora (el rango y, en `fg-muted`, la duración: «09:00–09:30  30 min») y Total (el nombre en peso 500 y el monto de 18 px con «IVA incluido»), separadas por líneas de 1 px `line-strong`. **Sucursal:** el diseño la trae, pero se omite hasta que la API la entregue en la reserva.
- **Depósito:** si `monto_a_pagar` es menor que el total, otra tarjeta con «Depósito a pagar ahora» (peso 500) y «Saldo en el local».
- **Cuenta regresiva:** «Su espacio se libera en 9:58» bajo el resumen, con `role="timer"`. Con menos de dos minutos aparece además un aviso en línea («Quedan menos de dos minutos. Complete el pago antes de que su espacio se libere.»). Los lectores de pantalla lo oyen **solo al entrar en ese rango**, nunca cada segundo. Sin `vence_en` no hay cuenta regresiva.
- **Plazo:** «Puede cancelar o cambiar la hora hasta N horas antes de la cita. Cada barbería define este plazo.» (14 px, `fg-subtle`), con el plazo real de la reserva.
- **Botón de pago:** en celular «Pagar ₡X»; en escritorio solo «Pagar», para no repetir el monto de su lado. Con PayPal, «Continuar a PayPal». Los dos de 16 px. Mientras se envía: «Procesando pago…» (los puntos van **solo al final**, sin ícono de puntos antes), relleno `surface`, texto `fg-muted`, pulso lento de opacidad y deshabilitado. El formulario baja a 60 % de opacidad y se deshabilita.
- **Estados:**
  - **Cargando:** esqueleto de un bloque y dos filas, con `aria-busy`.
  - **Vacío:** «No tiene una cita en curso» y «Elija servicio, barbero, fecha y hora. El resumen y el pago aparecen aquí.» con el botón «Reservar cita». En escritorio va centrado con el círculo de 56 px y el ícono de calendario; en celular, a la izquierda entre dos líneas.
  - **Error:** «Error: no pudimos cargar su reserva.» y «Reintentar».
  - **Esperando:** la etiqueta «Pago pendiente» (solo texto), «Esperando la confirmación de su pago…» y el botón «Verificar ahora». La página consulta sola cada 3 segundos. Con SINPE Móvil se muestran antes las instrucciones: número de destino y monto exacto, cada uno con su botón «Copiar» (que pasa a «Copiado» con un check).
  - **Pago rechazado (tarjeta, SINPE Móvil o PayPal):** aviso de error sin borde con el título «No pudimos cobrar su pago.», «Motivo: …» y «Su reserva se mantiene; elija otro método para pagarla.», y el botón «Elegir otro método», que no pierde la reserva. Con tarjeta el motivo es siempre «su tarjeta fue rechazada» (el texto de la pasarela viene en su idioma y no se muestra); con SINPE Móvil o PayPal es el de la API, o una frase genérica si no viene. La cuenta regresiva sigue a la vista mientras la reserva esté vigente. Un pago fallido (no rechazado) dice «No se pudo procesar su pago.» y ofrece «Intentar de nuevo». El mensaje «Su tarjeta ha sido rechazada. Por favor, pruebe con otra tarjeta o pague con SINPE Móvil.» queda solo para cuando la pasarela no acepta los datos de la tarjeta antes del cobro (en el formulario, bajo el número, con borde `danger-border` de campo).
  - **Estamos verificando su pago:** panel `surface` con borde `line` (no es un error), ícono de reloj, título en peso 500 y «El proveedor de pago no ha respondido. Su pago queda en verificación y no se cobrará dos veces. Puede consultarlo de nuevo cuando quiera.», con «Verificar ahora». Aparece con un 503 al consultar el estado o con el pago aún pendiente tras agotarse la espera del sondeo (3 minutos). Mientras haya un pago pendiente no se puede iniciar otro cobro.
  - **Reserva vencida:** aviso de error sin borde con «La reserva temporal venció.», «Pasó el plazo para pagar sin que el pago se confirmara, y el espacio se liberó. Elija otro espacio para reservar.» y «Elegir otro espacio», que vuelve a la barbería con el servicio, el barbero y el día elegidos. Si el pago se aprobó pero la reserva ya había vencido (devolución pendiente) no se presenta como éxito: «Recibimos su pago, pero la reserva no pudo confirmarse.», con la devolución a cargo de la barbería, y también «Elegir otro espacio».
  - **Cancelada en PayPal:** aviso informativo «El pago se canceló. Puede intentarlo de nuevo o elegir otro método.»
- **Pago aprobado:** la página pasa a «Reserva confirmada» en `/b/:enlace/reserva/:reserva/confirmada`: marca de check en `accent` y un único `h1` «Reserva confirmada» (24 px, peso 400, con el foco), la línea «Su pago de ₡X fue aprobado y el espacio es suyo.» (solo si se conoce el pago) y «Revise los datos de su cita aquí.» (no se promete correo). En escritorio, dos columnas: «Su cita» (el resumen, sin etiqueta de estado) y «Pago» con «Depósito pagado» y «Saldo en el local» (el monto del pago aprobado y el precio menos ese monto), la nota del plazo con el plazo real de la reserva y, abajo, «Volver a la barbería» y «Ver mis reservas» (este último solo cuando exista esa pantalla).

## Páginas de acceso y de lectura

### Columna de formulario
Las pantallas de acceso (crear cuenta e iniciar sesión) usan una sola columna centrada de 390 px en celular y 440 px en escritorio, con el logotipo en el encabezado. Un título de página (24 px), el formulario y, debajo, el enlace a la otra pantalla («¿Ya tiene cuenta? Ingresar» o «¿No tiene cuenta? Crear cuenta»). Estados de cada pantalla: reposo, escribiendo, enviando (campos y botón deshabilitados, `aria-busy`), error por campo, error general y bloqueo.

### Página de lectura
Las páginas de texto largo (términos y política de privacidad) usan un ancho de lectura de 65 a 75 caracteres, un único `h1` de 24 px, subtítulos `h2` de 18 px en peso 500 y párrafos de 16 px. Las tablas van dentro de un contenedor con desplazamiento horizontal, con encabezados `th scope="col"`. Si el texto es provisional, un aviso en línea informativo abre la página («Texto de ejemplo. El contenido final lo define el negocio.»). Bajo el título va una línea de 14 px en `fg-subtle` con la fecha de actualización y la versión. Los fragmentos de código dentro del texto usan Manrope sobre `surface` con radio de 8 px. En el encabezado, a la izquierda, un botón «Volver» (píldora con chevron, 44 px de alto) regresa a la página anterior o, si se llegó directo, a la portada; a la derecha va el logotipo.

## Movimiento y foco

- Transiciones de **150 ms** `ease-out`, solo para comunicar un cambio de estado (hover, apertura de un menú, giro de un chevron). Ninguna animación de entrada.
- **Foco de teclado** (`:focus-visible`): contorno de 1 px `accent` con 2 px de separación, en botones, chips, enlaces y filas.
- **Foco de campo:** el borde pasa a `accent`, sin contorno.
- Selección de texto: relleno `elevated` y texto `fg`.

### Reducción de movimiento (AC-04)

Con la reducción activada **no queda ninguna animación ni transición no esencial**: el pulso del esqueleto, el giro de los chevrons, el hover animado y el desplazamiento suave. Una sola regla en la capa base de `main.css` lo resuelve para toda la interfaz (`animation: none`, transiciones de 0,01 ms para que las de Vue terminen y `scroll-behavior: auto`); **los componentes no usan `motion-reduce:`**, porque eso decidiría solo por el dispositivo e ignoraría la elección de la persona.

| Elección | Atributo en `<html>` | Resultado |
|---|---|---|
| Sin elegir (por defecto) | ninguno | Sigue `prefers-reduced-motion` del dispositivo, en vivo |
| Activada | `data-motion="reduce"` | Sin movimiento, aunque el dispositivo lo permita |
| Desactivada | `data-motion="full"` | Con movimiento, aunque el dispositivo pida menos |

## Íconos e imágenes

- **Lucide**, trazo de 1,5 px, tamaños de 16, 18, 20 y 24 px, heredan el color del texto. Nunca emojis ni glifos como íconos.
- **Logos de marca:** los de WhatsApp, Instagram, Facebook, TikTok, Google Maps y Waze son los glifos oficiales de [Simple Icons](https://simpleicons.org) (licencia CC0), rellenos (`fill: currentColor`) sobre una cuadrícula de 24 px, en el color del texto. Se copian a `src/composables/brandIcons.ts`; no se agrega la librería como dependencia. Se muestran a 18 px (redes) y 16 px (mapas).
- El banner y el logo son **del negocio** y se muestran dentro de su marco. BarberHub no agrega fotografías de stock ni ilustraciones decorativas.
- **Logotipo:** el de BarberHub vive en `src/assets/images/`: `bh-logo-bone.png` (hueso, para el tema oscuro) y `bh-logo-navy.png` (azul marino `#000F2D`, para el claro). `BrandLogo` los une al tema en un único mapa (`LOGO_BY_THEME`) y lo muestra, con `alt="BarberHub"`, dimensiones explícitas y un alto mínimo de 24 px (por defecto, 56 × 35 px). `BrandLogo` recorta el margen transparente del archivo para que el logotipo quede completo y pegado al margen de la pantalla. Se usa solo en el encabezado de las pantallas de acceso, de lectura y de la aplicación. No se recolorea (sin filtros ni recuadros) ni se deforma.

## Mis reservas, detalle y Reprogramar

Rutas `/b/:enlace/mis-reservas` y `/b/:enlace/mis-reservas/:reserva`. Diseño de origen: el artefacto de la v7 (Mis reservas, detalle y Reprogramar).

- **Escritorio:** una tarjeta de 16 px con borde `line` y 32 px de padding que ocupa **todo el ancho** disponible (no una columna de 440 px). Título de 24 px con una línea `line` debajo y, bajo él, dos columnas separadas por una línea vertical de 1 px y 32 px de espacio. Nada se arma «como en celular».
  - **Mis reservas:** «Próximas» a la izquierda y «Pasadas» a la derecha. Cada fila lleva a la izquierda servicio, barbero, estado y monto, y a la derecha el día y el rango de hora.
  - **Detalle:** «Su cita» (resumen) a la izquierda y «Cambios y cancelación» a la derecha, con «Reprogramar» y «Cancelar» de 200 px. La confirmación de cancelar va en la misma columna. Si la reserva ya no admite cambios, la columna dice «Esta reserva ya no admite cambios ni cancelación.»
  - **Reprogramar:** «Fecha» a la izquierda, «Hora» a la derecha y, abajo, la barra con «Nueva cita…», «Volver a la reserva» y «Confirmar nuevo horario» (280 px).
- **Volver:** en escritorio hay un botón «Volver» sobre la tarjeta (en el detalle, «Mis reservas»). En «Mis reservas» regresa a la pantalla anterior del navegador (la reserva confirmada, la barbería…) y, si no hay ninguna, a la barbería.
- **Acceso desde la barbería:** con sesión iniciada, «Mis reservas» es un botón secundario (borde `line-strong`, ícono de calendario de 16 px) arriba a la derecha de «Reservar cita».
- **Celular:** una sola columna, igual que antes.

## Agenda del personal y detalle de la reserva

Rutas `/barberias/:barberia/agenda` (el día y los filtros viajan en la dirección: `?dia=`, `?estado=`, `?colaborador=`) y `/barberias/:barberia/agenda/:reserva` (el panel es una ruta hija). Diseño de origen: láminas 26 a 28 de la v7. Una sola pantalla para los dos roles; las diferencias salen de `useAgendaPermissions`.

- **Título:** «Agenda» para el dueño y «Mi agenda» para el colaborador. Debajo, la fecha («jue 5 nov 2026») y el conteo.
- **Día:** botones «Anterior», «Hoy» (deshabilitado si ya es hoy) y «Siguiente». Se trae el día completo (todas las páginas, siempre con `dia`) y los filtros se aplican en el cliente: «Estado» (Pendiente de pago, Confirmada, Atendida, Ausente, Cancelada, que junta las dos cancelaciones) y, solo para el dueño, «Colaborador» (los que tienen reservas ese día). «Limpiar filtros» aparece con un filtro activo. **«Expirada» no se muestra** en la agenda.
- **Estados:** cargando (esqueleto, `aria-busy`), vacío («No hay reservas este día.» con «Ir a hoy» en otra fecha; «No hay reservas con esos filtros.» con «Limpiar filtros») y error con «Reintentar».
- **Panel:** columna de 400 px a la derecha de la agenda en escritorio; en celular ocupa toda la pantalla y la lista se oculta, con «Volver a la agenda» arriba (conserva los filtros). Muestra el servicio (24 px) con la etiqueta de estado debajo, «Reserva» (servicio con su duración, barbero, fecha y hora, cliente) y «Cobro».
  - **Dueño:** «Total» (IVA incluido), «Saldo pendiente» e «Historial de estados» (el más reciente primero, con fecha y autor; un cambio de estado a sí mismo se lee «Reprogramada»).
  - **Colaborador:** solo «Saldo pendiente»; sin total, historial ni pagos.
  - **Acciones:** «Marcar atendida» (principal) y «Marcar ausente» (secundario) para los dos roles; el dueño, además y tras una línea, «Reprogramar» y «Cancelar reserva». «Atendida» se habilita desde la hora de inicio y «ausente» diez minutos después (la constante vive en `reservationClosing.ts`); antes, los botones van deshabilitados y una nota dice desde qué hora. Una reserva que no está confirmada no muestra acciones, solo una nota.
  - **Cancelar:** confirmación en línea («¿Cancelar la reserva de {cliente}?», «No, mantenerla» principal y «Sí, cancelar reserva» secundario, sin botón rojo). **Reprogramar:** el mismo selector de fecha y hora del cliente, en una columna y sin la línea del plazo (el dueño no tiene plazo).
  - **Progreso y resultado:** «Marcando como atendida…», «Reserva cancelada.», etc., en un aviso informativo con `role="status"`; los botones van con `disabled` mientras tanto. Un error de la API se muestra con su texto en un aviso `role="alert"`.
- **Pendiente:** el indicador de conexión («En vivo», «Reconectando…») es del #113; los botones «Bloqueos» y «Reserva asistida» no se muestran hasta que existan sus APIs (#101 y #102). **La vista del colaborador usa el diseño de la lámina 27 sin el filtro de colaborador y está pendiente de revisión visual** (la lámina «Mi agenda» aún no se diseña).

## Servicios del dueño (#76, entrega 1)

Rutas `/barberias/:barberia/servicios` (lista), `/servicios/nuevo` y `/servicios/:servicio` (formulario). Solo el dueño: la ruta lleva `requiresOwner` y el guard envía al colaborador a la agenda de esa barbería; la API sigue respondiendo 403. Diseño de origen: lámina 31 de la v7.

- **Lista:** imagen de 56 px (un bloque neutro con ícono si no tiene), nombre en 500, «30 min · ₡7.000 IVA incluido» (precio con cifras tabulares), etiqueta de estado, «Editar» y «Inactivar» o «Activar». Estados: cargando (esqueleto), vacío («Aún no tiene servicios.» con «Crear mi primer servicio») y error con «Reintentar».
- **Inactivar** pide confirmación («¿Inactivar «Rapado»?», «No, mantenerlo activo» principal y «Sí, inactivar» secundario). **Activar** no la pide. El resultado va en un aviso con `role="status"` y un fallo, en un aviso con «Reintentar» que repite el mismo cambio.
- **Formulario:** «Nombre del servicio», «Duración (minutos)», «Precio (₡, IVA incluido)» (solo dígitos), «Servicio activo» (solo al editar) e «Imagen del servicio (opcional)». Revisa antes de enviar (duración de 10 a 180 en múltiplos de 5, precio mayor que ₡0) y muestra los errores 422 de la API en su campo. El foco va al primer campo con error.
- **Imagen en dos pasos:** la API necesita el id del servicio, así que «Guardar servicio» crea el servicio y después sube la imagen. Si la subida falla, el servicio ya existe: se conserva su id, el error sale en el campo de la imagen y un nuevo «Guardar servicio» envía solo la imagen (no repite el alta).

### Diálogo de confirmación (`ConfirmDialog`, nuevo)
`role="alertdialog"` con `aria-modal`, título y texto enlazados con `aria-labelledby` y `aria-describedby`, sobre un fondo `canvas` al 80 %. Tarjeta de 16 px con borde `line` y superficie `surface`, sin sombra. El foco entra en la respuesta segura (el botón principal), se queda dentro con Tab y Mayús+Tab, Escape cancela y al cerrar vuelve a lo que lo abrió. Con `loading` se retienen los dos botones y Escape. Quien lo usa lo cierra cuando termina la acción.

### Campo de imagen (`ImageField`, nuevo)
Marco de 80 px con la vista previa (o la imagen actual, o un ícono), nombre del archivo y «WebP · 740 KB» en mono, botón «Elegir imagen» o «Cambiar imagen» y la ayuda debajo. Revisa el tipo (JPG, PNG o WebP) y los 2 MB antes de enviar, con los mensajes del diseño («El archivo pesa 3 MB. Elija una imagen JPG, PNG o WebP de hasta 2 MB.» y «Solo se aceptan imágenes JPG, PNG o WebP.»); un archivo rechazado queda a la vista con su error y no se envía. Es solo una comprobación para ahorrar el viaje: la API lee el tipo del contenido y decide, y su error (`errores.imagen`) se muestra igual. Es un `fieldset` con leyenda.

### Etiqueta de estado del servicio
`StatusLabel` suma `service_active` («Activo») y `service_inactive` («Inactivo», `fg-muted`), siempre solo texto.

### Divergencias de la lámina 31
- **Sin «Quitar imagen»** (31h, 31i, 31k): la API solo sube o reemplaza la imagen. Vuelve cuando exista el endpoint.
- **Interruptor de la lista:** el diseño dibuja un interruptor por fila; se usa un botón «Inactivar» o «Activar» con su nombre accesible, porque inactivar pide confirmación y el botón nombra la acción.
- **«Servicio activo» no está en «Nuevo servicio»:** la API crea el servicio siempre activo; para dejarlo inactivo se usa la lista.
- **«Guardado» en el formulario** (31o) se muestra con el aviso de éxito en línea, y «Cancelar» pasa a «Volver a la lista».
- **Cuadro neutro sin imagen** (31b): un marco con ícono de imagen, del mismo tamaño, en lugar de dejar la fila sin imagen.

## Equipo y horario de la barbería (#76, entrega 2)

Ruta `/barberias/:barberia/equipo` (nombre `staff-team`): solo el dueño (`requiresOwner`). Diseño de origen: lámina 32 de la v7. «Equipo» es una sección con sus apartados como rutas hijas; hoy solo existe «Horario de la barbería» (la ruta `staff-team` es el horario) y «Colaboradores» se suma como otra hija en la entrega 3. El enlace «Equipo» de la barra del personal aparece solo para el dueño y solo cuando la ruta existe.

- **Enlaces de sección (`SectionNav`, nuevo):** píldoras de 44 px con borde `line`; la actual lleva `aria-current="page"`, borde `line-strong`, fondo `elevated` y peso 500. Son **enlaces, no pestañas**: sin `role="tab"`, `tablist` ni `tabpanel`. «Colaboradores» solo aparece si `router.hasRoute('staff-team-members')`.
- **Horario semanal:** los siete días, de lunes a domingo, cada uno con su estado en texto («Abierto» o «Cerrado») y una o más franjas. Un día sin franjas es «Cerrado» («No atiende este día.») con el botón «Abrir este día», que solo agrega una fila vacía: no se inventa ninguna hora y nada se guarda hasta que la franja tiene inicio y fin válidos.
- **Cada cambio se aplica al instante:** «Guardar franja» (`POST /horario`), «Quitar» (`DELETE /horario/{franja}`) y, al editar las horas de una franja guardada, «Guardar cambio» o «Descartar». Cambiar una franja es quitar la vieja y agregar la nueva, en ese orden; si agregar la nueva falla, la vieja se restaura y el error de la fila lo dice («… Se mantuvo la franja de 09:00 a 19:00.»); si tampoco se puede restaurar, se vuelve a leer el horario y el día muestra el aviso. Cada acción termina en un aviso con `role="status"` («Franja agregada.», «Franja quitada.», «Franja actualizada.») o en un error en la misma fila.
- **Errores por fila** (`role="alert"`, ícono + texto, ambos campos con `aria-invalid` y `aria-describedby`): horas que no son HH:MM, cierre que no es posterior a la apertura, franja solapada con otra del día (nombra cuál) y los 409 y 422 de la API.
- **Excepciones por fecha (cierres):** lista desde hoy con la fecha («vie 25 dic 2026») y el motivo; «Quitar» pide confirmación («¿Quitar el cierre del …?», «No, mantener el cierre» principal y «Sí, quitar el cierre» secundario). El formulario «Agregar una fecha cerrada» lleva «Fecha» (`<input type="date">` con el estilo de `TextField` y `min` = hoy, en hora de Costa Rica) y «Motivo (opcional)», hasta 200 caracteres. Errores: fecha vacía o pasada y fecha ya cerrada (409), en el campo de la fecha.
- **Estados:** cargando (esqueleto, `aria-busy`), error («No pudimos cargar el horario» con «Reintentar»).

### Campo de hora (`TimeField`, nuevo)
Campo de texto de 96 px, Source Code Pro, centrado, con `HH:MM` de ejemplo, 24 horas. Se teclea sin los dos puntos (`0900` se muestra `09:00`) y solo admite dígitos. No usa el selector nativo del navegador porque sigue el idioma del navegador y puede mostrar 12 horas. Su etiqueta existe siempre (`sr-only`, o visible con `showLabel`). `invalid` y `describedBy` lo enlazan con el mensaje de la fila, que es de quien lo usa. Borde `field` y, con problema, `danger-border`.

### Divergencias de la lámina 32
- **Sin «Guardar horario»:** la API aplica cada franja por separado (agregar o quitar, sin editar ni guardado en bloque), así que cada acción se aplica al instante y muestra su resultado.
- **Sin interruptor «Abierto» por día:** un día cerrado se abre con «Abrir este día» y un día abierto se cierra al quitar su última franja. No hay un interruptor que cierre el día entero, porque eso sería quitar todas sus franjas de una vez.
- **«Invitaciones» no aparece** en Equipo (#100) y el subtítulo de la sección no las nombra.
- **Motivo del cierre:** el texto «Solo lo ve usted y su equipo» se cambia por «Una nota breve para recordar el motivo. Los clientes no la ven.»: la ruta de los cierres es solo del dueño.
- **Franja solapada:** el mensaje nombra la franja con la que se cruza («Esta franja se solapa con la de 09:00 a 12:30. Corrija las horas.») en lugar de numerarlas.
- **Láminas 32h a 32j** (reservas confirmadas que quedan fuera del horario o dentro de un cierre, HO-04): no se muestran. Dependen de una API que no existe y se difieren al #101; en `TeamScheduleView` queda un comentario donde irá el aviso.

## Bonos (pantallas del cliente)

Rutas `/b/:enlace/bonos` (Mis bonos y bonos a la venta) y `/b/:enlace/bonos/:bono/compra` (compra). Diseño de origen: las láminas 14 a 16 de la v7 (Mis bonos, compra de un bono y reservar con bono). Todo sale de la API tal cual: saldo, estado, vencimiento, precio, vigencia y servicios cubiertos nunca se calculan en el cliente.

- **Acceso:** con sesión iniciada, «Mis bonos» es un botón secundario (ícono de ticket de 16 px) junto a «Mis reservas», arriba a la derecha de «Reservar cita». El dueño y el colaborador (403 `prohibido`) no lo ven y, si abren la dirección, solo leen «Esta lista es para clientes».
- **Mis bonos y bonos a la venta:** una tarjeta de 16 px con borde `line` y 32 px de padding en escritorio, con el título «Bonos». Dos columnas separadas por una línea de 1 px: «Mis bonos» a la izquierda y «Bonos a la venta» a la derecha. En celular, una sola columna en ese orden. Debajo de las tarjetas de venta: «Los bonos solo sirven en {barbería}. Las sesiones que no use antes del vencimiento se pierden.» (14 px, `fg-subtle`).
- **Estados:** cargando (esqueleto, `aria-busy`); error («No pudimos cargar sus bonos.» con «Reintentar»); sin bonos comprados («Aún no tiene bonos»; lo mismo ve quien no tiene rol, nunca un error); sin bonos a la venta («Esta barbería no tiene bonos a la venta»); bonos desactivados (`bonos_activos` falso: «Esta barbería no ofrece bonos por ahora. No puede comprar bonos aquí. Los que ya compró siguen visibles y puede usarlos.», sin botones de compra).

### Tarjeta de bono
Tarjeta de 16 px (`surface`, borde `line`, 16 px de padding): el nombre en peso 500 y, a la derecha, la etiqueta de estado («Activo» o «Vencido», ver «Etiqueta de estado»); el saldo en 18 px y peso 500 («3 de 5 sesiones»); el vencimiento en 14 px `fg-muted` («Vence el mar 5 mar 2030»); y los servicios cubiertos en 14 px `fg-muted`. **Vencido:** el saldo es «0 sesiones» en `fg-muted` y la fecha dice «Venció el …» (el diseño dice «Vence el» también en el vencido; se usa el pasado, como en el aviso de reserva).

### Fila «Movimientos»
Botón plano de 44 px y ancho completo con una línea superior de 1 px y un chevron de 18 px que gira 180° en 150 ms (sin animación con `prefers-reduced-motion`), con `aria-expanded` y `aria-controls`. Expandida, una lista de filas de 56 px: el tipo en peso 500 (Compra, Apartado, Consumo, Reintegro), la fecha en `fg-muted` y, a la derecha, el cambio con su signo («+5 sesiones», «−1 sesión», «0 sesiones»), separadas por líneas de 1 px, de la más reciente a la más antigua. El diseño muestra «1 sesión» sin signo en todos; se usa el signo que entrega la API para que un apartado no se lea igual que un reintegro.

### Tarjeta de bono a la venta
Tarjeta de 16 px con filas Sesiones, Vigencia y Servicios cubiertos separadas por líneas `line-strong`, y al pie el precio en 18 px con «IVA incluido» en `fg-subtle` y el botón secundario «Comprar bono» (un enlace con el aspecto del botón; su nombre accesible incluye el del bono).

### Compra de un bono (Resumen y pago)
Misma estructura que «Resumen y pago» de la reserva: celular en una columna, escritorio en dos (a la izquierda «Su bono», a la derecha «Método de pago») y una barra de pago al pie. El resumen del bono es una tarjeta `surface` con filas Bono, Sesiones, Vigencia («60 días» y, en `fg-muted`, «Cuenta desde que se confirme el pago»), Servicios cubiertos y Total (con «IVA incluido»). Debajo: «El bono solo sirve en {barbería}. Se paga el precio completo ahora: sin depósito ni saldo en el local.» Sin cuenta regresiva ni «Cambiar reserva», porque un bono no retiene nada. Métodos: los que la API lista en `meta.metodos_de_pago` de «Bonos a la venta» para la pasarela de la barbería (`meta.pasarela`); nunca se supone ONVO. Con ONVO, tarjeta y SINPE Móvil, con los mismos formularios; los datos de la tarjeta nunca llegan a nuestra API. Con PayPal, una sola ficha «PayPal» (ícono de billetera), la nota «Al pagar, se abre PayPal para que confirme el pago.», el pie «Pago protegido por PayPal» y el botón «Continuar a PayPal»: solo viaja el método y, al volver de PayPal, la pantalla consulta el estado del pago igual que en la reserva.
- **Confirmación:** el bono se activa solo cuando la consulta del estado del pago a la API dice «aprobado» (se consulta cada 3 segundos; nunca por la dirección ni por lo que reporte el navegador). Mientras tanto: «Pago pendiente», «Esperando la confirmación de su pago…» y «Verificar ahora». Los estados rechazado, fallido, verificando y devolución pendiente son los de la pantalla de pago con el texto del bono («No se le cobró ni se activó el bono; elija otro método.», «Recibimos su pago, pero el bono no pudo activarse.»).
- **Bono activado:** marca de check en `accent` y un único `h1` «Bono activado» (24 px, peso 400, con el foco), «Su pago de ₡X fue aprobado y el bono ya está activo.» y el resumen (Bono, Sesiones, «Vence el» con la fecha de «Mis bonos», Servicios cubiertos), con «Ver mis bonos» y «Volver a la barbería».
- **Sin pasarela (`meta.pasarela` nula, la barbería no cobra):** el diseño no define un estado propio; no se ofrece comprar y se reutiliza el de «Esta barbería no tiene bonos a la venta» (en «Bonos» y en la compra, con «Ver mis bonos»).
- **Sin permiso o sin oferta:** bonos desactivados («Esta barbería no ofrece bonos por ahora»), bono retirado de la venta («Este bono ya no está a la venta») y dueño o colaborador («Esta compra es para clientes») muestran un estado vacío y ningún formulario.

### Opción «Pagar con bono» (panel de reserva)
Opción de `radiogroup` de 16 px (la misma ficha de método de pago: marca circular de 20 px, descripción en `fg-muted`) en «Forma de pago», sobre la barra inferior del panel de reserva de la portada. Se ofrece solo si la barbería tiene los bonos activos y la persona tiene un bono activo con sesiones que cubre el servicio elegido (los servicios cubiertos los entrega la API). Dos opciones: «Pagar con bono» («Se usará 1 sesión de un bono que cubra este servicio; sin depósito.») y «Pagar con tarjeta o SINPE Móvil» («Elegirá el método en el siguiente paso.»), esta última elegida por defecto. Con el bono elegido, el botón dice «Confirmar con bono» y la barra muestra «Pago 1 sesión de su bono» en lugar del precio. La API elige el bono (el que vence antes); el cliente no lo recalcula ni lo nombra.
- **Reserva confirmada con bono:** el panel muestra el resumen con «Confirmada», el aviso «Se usó 1 sesión de su bono.», el plazo real de la reserva («Puede cancelar o cambiar la hora sin costo hasta N horas antes de la cita. Si cancela a tiempo, la sesión vuelve a su bono. Cada barbería define este plazo.») y «Ver mis reservas» y «Ver mis bonos». No hay cuenta regresiva.
- **Errores estables:** `saldo-insuficiente` («Su bono ya no tiene sesiones disponibles.») y `bono-vencido` («Su bono vence antes de la fecha de la cita.») usan el aviso rojo de siempre con «No se reservó ni se usó ninguna sesión.» y la salida (pagar con tarjeta o SINPE Móvil, o elegir otra fecha); la opción vuelve a «Pagar con tarjeta o SINPE Móvil» y los bonos se leen de nuevo. La API no deja reserva, sesión apartada ni espacio retenido cuando falla.

## Preferencias (pantalla del cliente)

Ruta `/preferencias`, **pública** (sin sesión, sin API). Diseño de origen: láminas 17 a 19 de la v7 (Claude Design). Es la implementación de RF-48 y de AC-01 a AC-06. Un solo `h1` «Preferencias».

- **Dónde se guarda:** solo en el navegador, en `localStorage` (clave `barberhub.preferences`, dentro de `try/catch`; si falla, la elección vive en memoria durante la visita). No pide cuenta ni llama a la API. Un único almacén (`usePreferencesStore`) tiene las tres preferencias.
- **Se aplica de inmediato**, sin botón de guardar, y **antes del primer render**: un script en línea de `index.html` pone los atributos de `<html>` con las mismas reglas del almacén (una prueba los compara), para que la página no parpadee con el tema equivocado.
- **Atributos de `<html>`:** `data-theme="light|dark"` (el tema que se ve), `data-text-size="normal|large|xlarge"` y `data-motion="reduce|full"` (ausente si sigue al dispositivo).
- **Bloque reutilizable** (`PreferencesPanel`): la nota y los tres grupos, sin título ni página alrededor. La pantalla le pone el título y el navbar (sin «Volver»); en «Mi cuenta» será la pestaña «Apariencia» (#105). Nota: «Elija cómo quiere ver BarberHub: los cambios se aplican de inmediato y se guardan en este navegador.» (una sola frase, con un único punto)
- **Disposición:** celular, una columna: Tema, Tamaño del texto y Reducir el movimiento. Escritorio (desde 768 px): una tarjeta de 16 px con borde `line` y 32 px de relleno, título y nota arriba con una línea `line` debajo, y dos columnas separadas por una línea de 1 px y 32 px a cada lado: a la izquierda Tema y, debajo, Reducir el movimiento; a la derecha Tamaño del texto. Margen de pantalla de 20 px en celular y 32 px en escritorio; la tarjeta ocupa el ancho (máximo 1280 px).
- **Sin sesión**, encabezado con el logotipo y «Preferencias» como página activa. **Con sesión**, `/preferencias` redirige a «Mi cuenta · Apariencia» (ver «Mi cuenta»), que aloja el mismo bloque.

### Tema
Control segmentado (abajo) con Claro, Oscuro y Automático; Automático es el valor por defecto, sigue `prefers-color-scheme` y **cambia en vivo** si el dispositivo cambia. Bajo el control, en una región `aria-live="polite"`, la descripción de lo elegido (14 px, `fg-muted`): Claro «Fondo claro y texto oscuro.», Oscuro «Fondo oscuro y texto claro.», Automático «Sigue la preferencia de su dispositivo».

### Tamaño del texto
Tres niveles (AC-03): **Normal (100 %)**, **Grande (125 %)** y **Muy grande (150 %)**. Se aplica **escalando la raíz** (`font-size` de `<html>` en porcentaje, para multiplicar el tamaño que el navegador ya tenga), así que todo lo que está en `rem` crece; no hay tamaños de texto en píxeles. Los bordes, los radios y los íconos siguen en píxeles. Con 150 % ninguna pantalla pierde contenido ni funciones, y con el zoom del navegador al 200 % no hay desplazamiento horizontal. Se elige con el grupo de filas (`OptionGroup` y `OptionCard` en variante fila, con el valor «100 %» a la derecha). Debajo, la **muestra**.

### Interruptor (nuevo)
`button` con `role="switch"` y `aria-checked`, con su etiqueta y su descripción (`aria-labelledby`, `aria-describedby`). Área de 56 × 44 px y pista de 48 × 28 px (píldora, borde de 1 px). **Apagado:** pista transparente con borde `field` y perilla de 20 px en `fg-muted` a la izquierda. **Encendido:** pista `elevated` con borde `line-strong` y perilla en `accent` a la derecha, con un check de 12 px. El estado lo dicen la posición y el check, no solo el color. Foco: contorno de 1 px `accent` con 2 px de separación alrededor de la pista. Transición de 150 ms.
- **Reducir el movimiento:** «Reducir el movimiento» (peso 500) y «Quita las transiciones y animaciones. Por defecto sigue la preferencia de su dispositivo.» El interruptor **muestra el estado efectivo** (el del dispositivo mientras la persona no haya elegido). Al usarlo, la persona activa o desactiva la reducción y esa elección manda sobre el dispositivo.

### Control segmentado (nuevo)
`radiogroup` en píldora con borde `line-strong` y tres segmentos de al menos 44 px de alto (máximo 480 px de ancho). Ícono de 18 px (sol, luna, pantalla) **solo en escritorio o cuando el control se apila**. El segmento activo lleva relleno `elevated`, borde `line-strong`, texto en peso 500 e ícono en `accent`; los demás, peso 400 y hover `surface`. Flechas, Inicio y Fin cambian la opción y el foco; el elegido lleva el único `tabindex="0"`. **En celular con texto de 125 % o más** (`data-text-size` distinto de «normal», variante `text-scaled`) se apila en filas de 52 px con radio de 16 px.

### Muestra de texto (nueva)
Tarjeta de 16 px (`surface`, borde `line`) con «Muestra» (12 px, peso 500, `fg-muted`), un renglón de servicio y precio («Corte + barba», «₡10.000»), «45 min · jue 5 nov, 10:30» y un párrafo. Se escribe en `rem`, así que muestra el tamaño elegido.

### Enlace «Preferencias» (puntos de entrada)
Un solo componente (`PreferencesLink`), con la apariencia de la navegación con botón (sin subrayado, objetivo de 44 px):
- **Con sesión:** enlace del navbar del cliente (en la barra y en el panel de celular), como «Barberías».
- **Sin sesión:** en el encabezado de la portada, de Ingresar, de Crear cuenta, de Términos y condiciones y de Política de privacidad. **Celular:** solo un círculo de 44 px con el ícono de controles (Lucide `SlidersHorizontal`, 20 px) y borde `line-strong`; el nombre «Preferencias» sigue disponible para los lectores de pantalla. **Escritorio (768 px):** píldora de 44 px con la palabra. En Ingresar, Crear cuenta y la portada va a la derecha del logotipo; en Términos y Privacidad, «Volver» queda a la izquierda y el enlace va junto al logotipo, a la derecha, sin desacomodar ninguno (el enlace no se encoge).
- **Página activa:** relleno `elevated` y `aria-current="page"`.

## Mi cuenta (pantalla de la persona con sesión)

Rutas `/cuenta/datos`, `/cuenta/seguridad` y `/cuenta/apariencia` (`/cuenta` lleva a Datos), **comunes a cliente, colaborador y dueño** (RF-45, RF-40, RF-48). Diseño de origen: láminas 20 a 25 de la v7. Un solo `h1` «Mi cuenta» y un `h2` por sección. Usa la barra del personal si hay una barbería activa y el navbar del cliente si no (ver «Barra del personal»); el dueño y el colaborador entran por «Mi cuenta» de su menú.

### Navegación de las secciones (`AccountNav`)
**Enlaces**, no pestañas: cada sección es una ruta, así que no lleva `role="tab"`, `tablist` ni `tabpanel`; la actual lleva `aria-current="page"`.
- **Celular:** píldoras de 44 px (`rounded-pill`, borde `line`, texto `fg`) que **pasan a otra línea**: ninguna queda oculta. Actual: relleno `elevated`, borde `line-strong` y peso 500. Una línea `line` de 1 px las separa del contenido.
- **Escritorio (768 px):** lista vertical a la izquierda de 220 px, con la píldora actual rellena y las demás sin borde; una línea vertical de 1 px y 32 px de separación hacia el contenido. Todo dentro de la tarjeta de 16 px con borde `line` y 32 px de relleno, como «Preferencias».
- **Secciones visibles hoy:** Datos, Seguridad y Apariencia. **Avisos** (solo cliente, #106) e **Idioma** (#108) se suman cuando existan sus pantallas: no hay enlaces rotos.

### Datos
«Datos personales» y la línea «Su nombre, su teléfono y el correo con el que ingresa a BarberHub.» Tres campos (nombre, teléfono y correo, con las mismas reglas de filtrado que crear cuenta) en una columna de hasta 672 px y «Guardar cambios».
- **Cargando:** esqueleto de tres filas y `aria-busy`. **Error de carga:** `ErrorState` «No pudimos cargar sus datos» con «Reintentar».
- **Guardando:** campos y botón bloqueados, «Guardando…» (la palabra con los tres puntos, como en todo botón) y `aria-busy`.
- **Éxito:** aviso de éxito `role="status"` «Sus datos se guardaron.» bajo el botón; desaparece al volver a escribir.
- **Error por campo:** un correo ya registrado (422 en `errores.correo`) se muestra en el campo: «Este correo ya está registrado. Use otro correo.», con el foco en él. Otro error de la API es un aviso de error con lo escrito intacto.
- Con texto «Muy grande» (150 %) los campos se apilan sin desplazamiento horizontal.

### Seguridad
«Cambiar contraseña» y «Escriba su contraseña actual y elija una nueva.» Campos «Contraseña actual», «Contraseña nueva» (con la lista de requisitos debajo) y «Confirmar contraseña nueva», cada uno con el botón de ojo de `PasswordField`. La confirmación se valida **solo en el cliente** («Las contraseñas no coinciden.»). Una actual incorrecta (422 en `errores.contrasena_actual`) dice «La contraseña actual es incorrecta.» en su campo. Éxito: se vacían los campos y un aviso `role="status"` dice «Su contraseña se actualizó.»; **la sesión sigue abierta** (la API conserva este token y cierra los demás).

### Lista de requisitos con estado (`PasswordChecklist`)
Bajo «Contraseña nueva»: «La contraseña nueva debe tener» (14 px, peso 500) y una lista de cinco filas de 24 px: «Mínimo 8 caracteres», «Una letra mayúscula», «Una letra minúscula», «Un número» y «Un signo especial» (SE-07). Cada fila lleva un **ícono de 16 px y una palabra oculta para lectores de pantalla**, así que el estado no depende del color:
- **Pendiente:** círculo vacío, texto `fg-muted` («Pendiente: »).
- **Cumplido:** check, texto `fg` («Cumplido: »).
- **Falta** (solo tras un envío fallido): `X` y texto en `danger` («Falta: »).
El campo de la contraseña nueva apunta a la lista con `aria-describedby`. La frase única de crear cuenta no cambia.

### Apariencia
`h2` «Apariencia» y el mismo `PreferencesPanel` de «Preferencias» (tema, tamaño del texto y movimiento), con el mismo almacén: no llama a la API y se guarda en el navegador.

### Cambio obligatorio de la contraseña temporal
Ruta `/cambiar-contrasena`. La cuenta de un colaborador nuevo nace con una contraseña temporal (UP-04): el router la lee con `GET /cuenta` (`contrasena_temporal`) en la primera navegación de la sesión, y también cuando cualquier petición recibe 403 `contrasena-temporal`. **Mientras sea temporal, ninguna otra ruta se abre**. La columna de formulario de las pantallas de acceso (390 / 440 px), sin navbar: el logotipo a la izquierda y, a la derecha, solo «Cerrar sesión» (botón secundario).
- Título «Cambie su contraseña temporal» y una frase que explica por qué. Campos: «Contraseña temporal», «Contraseña nueva» (con la lista) y «Confirmar contraseña nueva». Misma lista, mismos estados y mismos mensajes que Seguridad; el 422 de la temporal dice «La contraseña actual es incorrecta.».
- **Éxito:** el formulario se reemplaza por «Su contraseña se actualizó. Ya puede usar BarberHub.» (`role="status"`) y el botón «Continuar», que lleva a donde iría la persona al ingresar: la página que pedía, el selector de barbería si es del personal, o la portada.

## Voz del texto

- Español de Costa Rica, «usted».
- **Botones:** verbo primero, sin punto: «Reservar cita», «Continuar al pago», «Reintentar».
- **Errores:** dicen qué pasó y qué hacer, en una o dos frases. Sin lenguaje técnico ni mensajes crudos del servidor.
- **Formatos:** precios `₡10.000` (punto como separador de miles); horas `10:30`; rangos `10:30–11:15`; fechas «jue 5 nov 2026» y, sin año, «sáb 7 nov». En los textos legales, la fecha de actualización va completa: «5 de noviembre de 2026».
- Mayúscula solo al inicio de la frase y en nombres propios. Sin signos de exclamación en mensajes del sistema.
- **Errores de acceso:** un único mensaje genérico, sin revelar qué falló («El correo o la contraseña no son correctos.»). El bloqueo por intentos muestra el tiempo restante como `m:ss` («Demasiados intentos. Intente de nuevo en 4:32», sin punto final porque termina en la cuenta) con cuenta regresiva y el botón deshabilitado.
- **Contenido legal:** los textos de `docs/legal/` se muestran tal cual; no se editan en las vistas.

## Accesibilidad

- WCAG 2.2 AA: texto de al menos 4,5:1, componentes y foco de al menos 3:1.
- Objetivos de 44 px como mínimo.
- Ningún estado depende solo del color o del tono: la selección lleva check o indicador, y los estados llevan texto (solo el texto, sin punto, en todas las pantallas). Excepción decidida: los chips de hora y de fecha (relleno, peso 500 y `aria-pressed`, sin check).
- `<html lang="es-CR">`. Un solo `h1` por pantalla.
- Chips como `aria-pressed`; servicios y métodos de pago como `radiogroup`; página activa con `aria-current="page"`; avisos con `role="status"` o `role="alert"`; zonas que cargan con `aria-busy`.
- Todo funciona con teclado y respeta la reducción de movimiento (la del dispositivo o la que la persona elija).
- **Texto ampliable:** con «Muy grande» (150 %) ninguna pantalla pierde contenido ni funciones y, con el zoom del navegador al 200 %, no hay desplazamiento horizontal (ver «Preferencias»).
- Interruptores como `role="switch"` con `aria-checked`; grupos de opciones como `radiogroup` con nombre accesible.

## Notas para la implementación

- **Pago con tarjeta:** el número, el vencimiento y el CVV **no se envían a nuestra API**. El método de pago se crea directamente con ONVO usando su llave pública, y a nuestro servidor solo llega su identificador.
- **Resumen de pago:** muestra el total de la reserva y, si la barbería cobra un depósito, una línea adicional «Depósito a pagar ahora». Una reserva temporal vence a los 10 minutos y la pantalla debe mostrarlo. Los detalles están en «Resumen y pago».
- **Pantallas con sus tres estados:** toda pantalla que carga datos tiene el estado cargando (esqueleto), el de error y el vacío.
- Los tipos de TypeScript se generan desde `openapi/v1.json`; los nombres de campos están en español.

## Qué hacer y qué no

### Hacer
- Separar superficies con líneas de 1 px y cambios de superficie.
- Usar el hueso solo en foco, check de selección, página activa y logo.
- Marcar la selección con tono, borde discreto y un check o indicador (los chips de hora y de fecha, con relleno y peso 500).
- Mantener un único botón principal por región.
- Usar Manrope en pesos 400 y 500, con cifras tabulares en datos.
- Escribir mensajes que nombran el problema y la salida.

### No hacer
- Sombras, degradados en componentes (el fondo de la página es la única excepción), desenfoque, vidrio, brillos o halos.
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
  --page-glow: rgba(238, 240, 240, 0.04);
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
  /* El único degradado: un destello tenue en la esquina inferior derecha, sobre canvas. */
  background:
    radial-gradient(120% 700px at 100% 100%, var(--page-glow), transparent) fixed,
    var(--canvas);
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

/* Tema claro: los mismos nombres con otros valores (ver «Tema claro»). */
:root[data-theme='light'] {
  color-scheme: light;
  --page-glow: rgba(0, 15, 45, 0.05);
  --canvas: #f5f6f6;
  --surface: #ffffff;
  --elevated: #e8ebed;
  --line: #dde0e2;
  --line-strong: #c4c9cc;
  --line-hover: #a9afb3;
  --field: #7b8185;
  --fg: #14181a;
  --fg-muted: #4f5559;
  --fg-subtle: #5d6366;
  --accent: #000f2d;
  --accent-hover: #2b3f69;
  --btn-hover: #dce1e4;
  --btn-active: #f0f2f3;
  --danger: #a5231f;
  --danger-surface: #fdeceb;
  --danger-border: #c4413a;
  --warning: #7a5300;
  --warning-surface: #fbf0d6;
}

/* Tamaño del texto: se escala la raíz y todo lo que está en rem la sigue. */
:root[data-text-size='large'] { font-size: 125%; }
:root[data-text-size='xlarge'] { font-size: 150%; }

/* Reducción de movimiento: sin elegir manda el dispositivo; data-motion manda sobre él. */
@media (prefers-reduced-motion: reduce) {
  :root:not([data-motion='full']), :root:not([data-motion='full']) *,
  :root:not([data-motion='full']) *::before, :root:not([data-motion='full']) *::after {
    animation: none !important; transition-duration: 0.01ms !important; transition-delay: 0s !important; scroll-behavior: auto !important;
  }
}
:root[data-motion='reduce'], :root[data-motion='reduce'] *,
:root[data-motion='reduce'] *::before, :root[data-motion='reduce'] *::after {
  animation: none !important; transition-duration: 0.01ms !important; transition-delay: 0s !important; scroll-behavior: auto !important;
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

El tema claro redefine las mismas variables `--color-*` bajo `:root[data-theme='light']`, así que ninguna clase cambia. La variante `text-scaled:` (`@custom-variant`) aplica cuando el texto está por encima del 100 %.

Ejemplos de uso: `bg-canvas text-fg`, `border border-line-strong rounded-pill bg-elevated hover:bg-btn-hover active:bg-btn-active`, `tabular-nums`.

## Pendiente

- **Mi cuenta:** las secciones «Avisos» (#106) e «Idioma» (#108) se suman cuando existan sus pantallas. El dueño y el colaborador entran a «Mi cuenta» por el menú de su barra (ver «Barra del personal»).
- **Agenda del personal (láminas 26 a 28):** se omitió lo que la API aún no entrega. (1) «Pago», «Depósito» y «Estado del depósito» del dueño: la API aún no entrega el pago del detalle (solo `precio` y `saldo_pendiente`); se muestran «Total» y «Saldo pendiente». (2) «(dueño)» junto al autor del historial: la API entrega el nombre o «Sistema», sin rol. (3) «Le avisamos al cliente por correo»: el aviso llega con #96. (4) El desplegable de barbería de la lámina 26b: se usa el selector `/negocios` con el enlace «Cambiar de barbería». (5) Las etiquetas de estado van sin punto, como en el resto de pantallas (ver «Etiqueta de estado»).
- **Por confirmar con ONVO:** los formatos de la identificación del SINPE Móvil de cada tipo (tabla de «Campo de texto»).
- **Pantalla de pago:** la fila «Sucursal» y el banner y el logo de la cabecera dependen de que la API los entregue; el pago muestra la cabecera con los mismos datos de ejemplo que la portada.
- **Bonos (láminas 14 a 16):** se omitió lo que la API no entrega. (1) Pagar con PayPal en la compra: el contrato de la compra solo admite tarjeta y SINPE Móvil y el bono no informa la pasarela. (2) El nombre, el saldo y el vencimiento del bono dentro de la opción «Pagar con bono», y «No vale para {fecha}»: la API elige el bono al reservar y el cliente no lo recalcula; si el bono vence antes de la cita, la API responde `bono-vencido`. (3) «Le quedan N» tras reservar con bono: la reserva creada no trae el saldo restante (se ve en «Mis bonos»). (4) La hora exacta límite de cancelación («hasta el jue 5 nov, 06:30»): se muestra el plazo real en horas (`plazo_cancelacion_horas`). (5) «Su espacio se mantiene» en los errores de bono: la API no crea la reserva cuando falla, así que el aviso dice que no se reservó nada. (6) El punto de «Activo» y «Vencido» (ver «Etiqueta de estado»). (7) «Sucursal», como en el resto de pantallas.
- **Pantallas por diseñar:** resultado del pago y reserva confirmada, Mis reservas con detalle, cancelar y reprogramar, elegir negocio, y del dueño: servicios, cobro y datos del negocio.
- **Logo, banner, dirección y redes** dependen de los endpoints de datos públicos del negocio y de la subida de archivos. Mientras tanto, la portada muestra datos de ejemplo (banner y logo neutros, dirección y redes ficticias) marcados como temporales en `ShopView.vue`.
