/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Llave pública de pruebas de ONVO (`onvo_test_…`). Vacía si no se configuró. */
  readonly VITE_ONVO_PUBLISHABLE_KEY?: string
  /** Dirección base de la API de ONVO. */
  readonly VITE_ONVO_API_URL?: string
}
