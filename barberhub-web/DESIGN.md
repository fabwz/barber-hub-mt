# BarberHub — Guía de diseño

> Consola oscura, sobria y precisa: un lienzo de grafito frío, líneas finas de 1 px y un solo acento hueso que se usa con contención. Todo es plano, sin sombras ni degradados en los componentes; solo el fondo de la página lleva un destello muy tenue en una esquina.

Este documento es la fuente de verdad visual del cliente web (`barberhub-web`: Vue 3, TypeScript y Tailwind CSS 4). Toda interfaz nueva lo sigue. Si algo no está aquí, se elige la opción más sobria y se anota.

**Tema:** oscuro (el claro queda pendiente, ver «Pendiente»).
**Idioma de la interfaz:** español de Costa Rica, tratando de «usted».

## Principios

1. **Una sola interfaz para todas las barberías.** No hay un color por negocio. La identidad de cada barbería viene de sus datos: logo, nombre, dirección, ciudad, teléfono, redes y un banner opcional.
2. **Casi todo es gris.** El hueso es el único color de marca y solo señala foco, selección con ícono, estado «confirmada» y la página activa.
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

**Única excepción, el fondo de la página:** un degradado radial muy tenue en la esquina inferior derecha de la ventana, sobre `canvas`: `radial-gradient(120% 700px at 100% 100%, rgba(238,240,240,0.04), rgba(238,240,240,0)) fixed`. Se declara una sola vez, en la capa base de `main.css`, y ningún componente lo usa (la prueba de reglas de diseño sigue fallando si aparece un degradado en `src/components`).

## Componentes

### Botón principal (tonal)
Píldora de `48 px` de alto en flujos de pago y `44 px` en el resto. Relleno `elevated`, texto `fg` en peso 500, 14 px, borde de 1 px `line-strong`. Hover: relleno `btn-hover`. Presionado: `btn-active`. Un solo botón principal por región visual. No existe un botón relleno de hueso. **Excepción:** en la barra de pago el texto es de 16 px, del mismo tamaño que el monto que tiene al lado.

### Botón secundario
Píldora transparente, borde de 1 px `line-strong`, texto `fg` en peso 400. Hover: borde `line-hover`.

### Enlace
Texto `fg` con subrayado. Hover: `accent-hover`. Nunca se resalta con hueso en reposo.

**Navegación con apariencia de botón** («Volver», «Cambiar servicio u hora»): aunque sea un enlace, **no lleva subrayado**. Es una píldora de 44 px de alto, texto `fg` de 14 px en peso 500, con un chevron o una flecha de 16 a 18 px (trazo 1,5). Hover: relleno `surface`.

### Chip de espacio horario y de fecha
**Hora.** Botón de 44 px de alto con radio de campo (8 px), ancho mínimo de 72 px, padding horizontal de 16 px y texto de 14 px con cifras tabulares.
- **Disposición (escritorio):** agrupados por turno (Mañana, Tarde y Noche, con la etiqueta en 14 px `fg-muted`). Cada turno va en **una sola línea** con desplazamiento horizontal, sin barra visible, igual que la franja de fechas.
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

### Aviso en línea informativo
Una fila con un ícono de información de 16 px y texto, sin color de estado, dentro de una tarjeta de 16 px con borde de 1 px `line` y relleno `surface`. Se usa para indicar que un contenido es de ejemplo. La primera frase va en peso 500 y la segunda en `fg-muted`.

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

**Dónde no se muestra:** en la pantalla de pago no se muestra «Pago aprobado», porque su punto `accent` (hueso) no se distingue del texto y el aviso «Su pago de ₡X fue aprobado.» ya lo dice. Tampoco «Pendiente de pago» en el resumen de una reserva temporal: lo dice la cuenta regresiva. «Pago pendiente» (amarillo) y «Pago rechazado» o «fallido» (rojo) sí se muestran.

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
- **Dos columnas iguales** separadas por una línea de 1 px y 32 px de espacio a cada lado. Izquierda: «Servicio» (la fila de servicio de arriba) y «Barbero». Derecha: «Fecha» y «Hora». En celular el orden es el mismo, apilado. Los títulos de paso son de 18 px, peso 500.
- **Barbero:** un `select` de 52 px, radio de 8 px, borde `field`, texto de 16 px en peso 500 y chevron de 18 px. La primera opción es «Sin preferencia».
- **Sin servicio elegido:** la columna derecha muestra un círculo de 56 px (borde `line-strong`, relleno `surface`) con un ícono de calendario de 24 px, el título «Elija un servicio» (18 px, peso 500) y una línea de 14 px en `fg-muted`.
- **Cargando horas:** 8 píldoras de esqueleto de 72 × 44 px.
- **Error al cargar horas:** caja de 8 px de radio con relleno `danger-surface` y 16 px de padding: ícono de 20 px en `danger`, el título «No pudimos cargar los horarios.» en `danger` y peso 500, la línea «Revise su conexión e intente de nuevo. Lo que ya eligió se mantiene.» y el botón secundario «Reintentar».
- **Sin horas ese día:** un recuadro con líneas `line` arriba y abajo, el título «No quedan horas el vie 6 nov con Mauricio.», una línea que sugiere probar otro día, botones secundarios «Ver sáb 7 nov» (los días siguientes con horas) y un botón de texto «Sin preferencia» con flecha.
- **Barra inferior:** una fila con una línea `line` encima. A la izquierda el resumen (16 px): el servicio en peso 500 y, en `fg-muted`, la fecha y la hora con el barbero («sáb 7 nov, 08:15 con Mauricio»), con un espacio entre la hora y «con». Sin hora elegida dice «Elija una hora para continuar» (o «Elija un servicio para continuar»). A la derecha, el total (18 px, tabulares) con «IVA incluido» y el botón principal «Reservar» de 48 px y 280 px de ancho. Deshabilitado: borde `line`, relleno `surface` y texto `fg-muted`, sin opacidad. En celular la barra queda fija al pie de la pantalla, con una línea superior, el resumen dentro de una tarjeta `surface` y el botón a todo el ancho.
- **Reserva creada:** la lista de servicios y el barbero **desaparecen**: ya hay una reserva y no tiene sentido seguir eligiendo. «Su reserva» queda en **una sola columna de 440 px centrada** en el recuadro, con el resumen (la tarjeta de «Resumen y pago»), la cuenta regresiva de 10 minutos («Su espacio se libera en 9:58», sin etiqueta de estado) y dos botones de 48 px: el principal «Continuar al pago» y el secundario **«Editar reserva»**, que vuelve a la selección para cambiar servicio, barbero, día u hora. **Cancela la reserva temporal** antes de volver (RF-16, RE-04), para que su espacio quede libre y no choque con el mismo horario; si la cancelación falla, vuelve igual, porque la reserva vence sola. Con un pago en proceso no se ofrece: en su lugar sale el aviso «Tiene un pago en proceso. Continúe al pago para ver su estado.», porque cancelar dejaría un cobro sin reserva. Si vence, un aviso «La reserva temporal venció.» y el botón «Elegir otro horario».
- **Espacio ocupado (409):** bajo la hora, «Otros espacios cercanos» como chips con los espacios que devuelve la API; al elegir uno se reemplaza solo la hora.

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
  - **Esperando:** punto amarillo y «Pago pendiente», «Esperando la confirmación de su pago…» y el botón «Verificar ahora». La página consulta sola cada 3 segundos. Con SINPE Móvil se muestran antes las instrucciones: número de destino y monto exacto, cada uno con su botón «Copiar» (que pasa a «Copiado» con un check).
  - **Tarjeta rechazada:** vuelve el formulario, con el mensaje «Su tarjeta ha sido rechazada. Por favor, pruebe con otra tarjeta o pague con SINPE Móvil.» bajo el número (borde `danger-border`, ícono y texto `danger`). El motivo que devuelve la API no se muestra en este caso.
  - **SINPE Móvil o PayPal rechazados:** un aviso de error con el motivo de la API y «Elegir otro método».
  - **Proveedor sin respuesta:** aviso de error («El proveedor de pago no responde. Su pago queda en verificación y no se cobrará dos veces.») y «Verificar ahora».
  - **Vencida:** «La reserva temporal venció» y «Elegir otro horario».
  - **Cancelada en PayPal:** aviso informativo «El pago se canceló. Puede intentarlo de nuevo o elegir otro método.»
- **Temporal hasta el issue #84:** los avisos de pago aprobado, rechazado y vencida son el mínimo para cerrar el pago; la confirmación y sus pantallas finales se diseñan aparte.

## Páginas de acceso y de lectura

### Columna de formulario
Las pantallas de acceso (crear cuenta e iniciar sesión) usan una sola columna centrada de 390 px en celular y 440 px en escritorio, con el logotipo en el encabezado. Un título de página (24 px), el formulario y, debajo, el enlace a la otra pantalla («¿Ya tiene cuenta? Ingresar» o «¿No tiene cuenta? Crear cuenta»). Estados de cada pantalla: reposo, escribiendo, enviando (campos y botón deshabilitados, `aria-busy`), error por campo, error general y bloqueo.

### Página de lectura
Las páginas de texto largo (términos y política de privacidad) usan un ancho de lectura de 65 a 75 caracteres, un único `h1` de 24 px, subtítulos `h2` de 18 px en peso 500 y párrafos de 16 px. Las tablas van dentro de un contenedor con desplazamiento horizontal, con encabezados `th scope="col"`. Si el texto es provisional, un aviso en línea informativo abre la página («Texto de ejemplo. El contenido final lo define el negocio.»). Bajo el título va una línea de 14 px en `fg-subtle` con la fecha de actualización y la versión. Los fragmentos de código dentro del texto usan Manrope sobre `surface` con radio de 8 px. En el encabezado, a la izquierda, un botón «Volver» (píldora con chevron, 44 px de alto) regresa a la página anterior o, si se llegó directo, a la portada; a la derecha va el logotipo.

## Movimiento y foco

- Transiciones de **150 ms** `ease-out`, solo para comunicar un cambio de estado (hover, apertura de un menú). Ninguna animación de entrada.
- **Foco de teclado** (`:focus-visible`): contorno de 1 px `accent` con 2 px de separación, en botones, chips, enlaces y filas.
- **Foco de campo:** el borde pasa a `accent`, sin contorno.
- Selección de texto: relleno `elevated` y texto `fg`.
- Con `prefers-reduced-motion`, las transiciones y animaciones se reducen al mínimo.

## Íconos e imágenes

- **Lucide**, trazo de 1,5 px, tamaños de 16, 18, 20 y 24 px, heredan el color del texto. Nunca emojis ni glifos como íconos.
- **Logos de marca:** los de WhatsApp, Instagram, Facebook, TikTok, Google Maps y Waze son los glifos oficiales de [Simple Icons](https://simpleicons.org) (licencia CC0), rellenos (`fill: currentColor`) sobre una cuadrícula de 24 px, en el color del texto. Se copian a `src/composables/brandIcons.ts`; no se agrega la librería como dependencia. Se muestran a 18 px (redes) y 16 px (mapas).
- El banner y el logo son **del negocio** y se muestran dentro de su marco. BarberHub no agrega fotografías de stock ni ilustraciones decorativas.
- **Logotipo:** el de BarberHub vive en `src/assets/images/bh-logo-bone.png` (versión hueso, para el tema oscuro) y se muestra con el componente `BrandLogo`, con `alt="BarberHub"`, dimensiones explícitas y un alto mínimo de 24 px (por defecto, 56 × 35 px). `BrandLogo` recorta el margen transparente del archivo para que el logotipo quede completo y pegado al margen de la pantalla. Se usa solo en el encabezado de las pantallas de acceso, de lectura y de la aplicación. No se recolorea ni se deforma. Las versiones negra y azul grisáceo se suman con el tema claro.

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
- Ningún estado depende solo del color o del tono: la selección lleva check o indicador, y los estados llevan punto y texto. Excepción decidida: los chips de hora y de fecha (relleno, peso 500 y `aria-pressed`, sin check).
- `<html lang="es-CR">`. Un solo `h1` por pantalla.
- Chips como `aria-pressed`; servicios y métodos de pago como `radiogroup`; página activa con `aria-current="page"`; avisos con `role="status"` o `role="alert"`; zonas que cargan con `aria-busy`.
- Todo funciona con teclado y respeta `prefers-reduced-motion`.

## Notas para la implementación

- **Pago con tarjeta:** el número, el vencimiento y el CVV **no se envían a nuestra API**. El método de pago se crea directamente con ONVO usando su llave pública, y a nuestro servidor solo llega su identificador.
- **Resumen de pago:** muestra el total de la reserva y, si la barbería cobra un depósito, una línea adicional «Depósito a pagar ahora». Una reserva temporal vence a los 10 minutos y la pantalla debe mostrarlo. Los detalles están en «Resumen y pago».
- **Pantallas con sus tres estados:** toda pantalla que carga datos tiene el estado cargando (esqueleto), el de error y el vacío.
- Los tipos de TypeScript se generan desde `openapi/v1.json`; los nombres de campos están en español.

## Qué hacer y qué no

### Hacer
- Separar superficies con líneas de 1 px y cambios de superficie.
- Usar el hueso solo en foco, check de selección, punto de «confirmada», página activa y logo.
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
    radial-gradient(120% 700px at 100% 100%, rgba(238, 240, 240, 0.04), rgba(238, 240, 240, 0)) fixed,
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
- **Por confirmar con ONVO:** los formatos de la identificación del SINPE Móvil de cada tipo (tabla de «Campo de texto»).
- **Pantalla de pago:** la fila «Sucursal» y el banner y el logo de la cabecera dependen de que la API los entregue; el pago muestra la cabecera con los mismos datos de ejemplo que la portada.
- **Pantallas por diseñar:** resultado del pago y reserva confirmada, Mis reservas con detalle, cancelar y reprogramar, elegir negocio, y del dueño: servicios, cobro y datos del negocio.
- **Logo, banner, dirección y redes** dependen de los endpoints de datos públicos del negocio y de la subida de archivos. Mientras tanto, la portada muestra datos de ejemplo (banner y logo neutros, dirección y redes ficticias) marcados como temporales en `ShopView.vue`.
