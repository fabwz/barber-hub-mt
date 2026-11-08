# barberhub-web

Cliente web de BarberHub: Vue 3, TypeScript, Vite, Vue Router, Pinia y Tailwind CSS 4.

Por ahora solo existe la base del proyecto: una vista de inicio que comprueba que el router funciona. Todavía no hay llamadas a la API ni pantallas de negocio.

## Requisitos

- Node.js 26 (LTS), fijado en `.nvmrc` y en `engines` de `package.json`.
- npm (incluido con Node.js).

## Instalación

```sh
npm ci
cp .env.example .env
```

## Scripts

| Script                 | Qué hace                             |
| ---------------------- | ------------------------------------ |
| `npm run dev`          | Servidor de desarrollo de Vite.      |
| `npm run build`        | Compila para producción en `dist/`.  |
| `npm run preview`      | Sirve la compilación local.          |
| `npm run lint`         | ESLint.                              |
| `npm run format:check` | Verifica el formato con Prettier.    |
| `npm run type-check`   | Verifica los tipos con `vue-tsc`.    |
| `npm run test`         | Pruebas con Vitest y Vue Test Utils. |
| `npm run types`        | Genera los tipos de la API.          |

## Variable de entorno

Se define en `.env` (copia de `.env.example`). Nunca se escriben secretos en el repositorio.

| Variable                    | Valor de ejemplo             | Uso                                                                                                                                  |
| --------------------------- | ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| `VITE_API_URL`              | `http://127.0.0.1:8000`      | Destino del proxy de desarrollo hacia la API.                                                                                        |
| `VITE_ONVO_PUBLISHABLE_KEY` | _(vacía)_                    | Llave pública de pruebas de ONVO Pay (`onvo_test_…`). Con otra llave el cliente no llama a ONVO y muestra un error de configuración. |
| `VITE_ONVO_API_URL`         | `https://api.onvopay.com/v1` | Dirección base de la API de ONVO, a la que el navegador envía los datos de la tarjeta.                                               |

## Tipos de la API

Los tipos de la API no se escriben a mano: se generan con `openapi-typescript` desde el contrato de la API.

```sh
npm run types
```

- **Origen:** `../barberhub-api/openapi/v1.json`. **Destino:** `src/api/schema.d.ts`, que se versiona, lleva el comentario «Archivo generado, no editar» y está excluido de ESLint y Prettier.
- **Cuándo regenerarlos:** cada vez que cambia `openapi/v1.json` (la API se regenera con `composer openapi`). Se hace un commit con el contrato nuevo y los tipos nuevos. La CI vuelve a generarlos y falla si `schema.d.ts` difiere de lo versionado.
- **Cliente:** `src/api/client.ts` usa `openapi-fetch` con la dirección base `/api/v1` (las rutas del contrato son relativas a su `servers.url`). Todo error llega como `ApiError` (`src/api/errors.ts`). La tienda de sesión registrará el token con `setTokenProvider` y el cierre de sesión ante un 401 con `setUnauthorizedHandler`.
- **Proxy de desarrollo:** el cliente llama a `/api/v1` en su propio origen y el servidor de Vite reenvía `/api` a `VITE_API_URL` (`vite.config.ts`), de modo que en desarrollo el navegador no necesita CORS. La API no cambia: cuando el cliente se sirva desde otro origen, habrá que configurar CORS en la API (pendiente).

## Estructura de `src/`

```
src/
├── assets/
│   ├── fonts/     Manrope 400 y 500 (woff2, latin)
│   ├── images/
│   └── styles/    main.css: Tailwind, tokens (@theme) y estilos base
├── api/           schema.d.ts (generado), client.ts, errors.ts, index.ts
├── components/
├── composables/   funciones use…
├── router/
├── stores/        tiendas Pinia useXStore
├── views/         vistas terminadas en View
├── __tests__/
├── App.vue
└── main.ts
```

El alias `@` apunta a `src/`.

## Diseño

La guía visual (tokens, componentes, estados y accesibilidad) es [DESIGN.md](DESIGN.md). Los tokens de color, tipografía y radios viven una sola vez, en el bloque `@theme` de `src/assets/styles/main.css`.

## Licencia de la fuente

La tipografía Manrope se sirve desde `src/assets/fonts/` (copiada del paquete `@fontsource/manrope`, que no queda como dependencia). Manrope se distribuye bajo la licencia SIL Open Font License 1.1 (OFL).
