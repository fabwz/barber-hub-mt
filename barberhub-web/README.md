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

## Variable de entorno

Se define en `.env` (copia de `.env.example`). Nunca se escriben secretos en el repositorio.

| Variable       | Valor de ejemplo        | Uso                                          |
| -------------- | ----------------------- | -------------------------------------------- |
| `VITE_API_URL` | `http://127.0.0.1:8000` | Dirección base de la API. Todavía no se usa. |

## Estructura de `src/`

```
src/
├── assets/
│   ├── fonts/     Manrope 400 y 500 (woff2, latin)
│   ├── images/
│   └── styles/    main.css: Tailwind, tokens (@theme) y estilos base
├── api/
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
