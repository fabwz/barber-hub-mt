/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Llave pública de pruebas de ONVO (`onvo_test_…`). Vacía si no se configuró. */
  readonly VITE_ONVO_PUBLISHABLE_KEY?: string
  /** Dirección base de la API de ONVO. */
  readonly VITE_ONVO_API_URL?: string
  /** Llave de la aplicación de Reverb (la pública, `REVERB_APP_KEY` de la API). Vacía: sin tiempo real. */
  readonly VITE_REVERB_APP_KEY?: string
  /** Servidor de Reverb (`REVERB_HOST`). */
  readonly VITE_REVERB_HOST?: string
  /** Puerto de Reverb (`REVERB_PORT`). */
  readonly VITE_REVERB_PORT?: string
  /** `http` o `https` (`REVERB_SCHEME`). */
  readonly VITE_REVERB_SCHEME?: string
}
