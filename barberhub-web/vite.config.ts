import { fileURLToPath, URL } from 'node:url'

import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// Dirección de la API en desarrollo (el valor de .env.example si no hay .env).
const env = loadEnv('development', process.cwd(), 'VITE_')
const apiUrl = env.VITE_API_URL || 'http://127.0.0.1:8000'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    // El navegador llama a /api/v1 en su propio origen y Vite lo reenvía a la API,
    // así que en desarrollo no hace falta CORS.
    proxy: {
      '/api': { target: apiUrl, changeOrigin: true },
    },
  },
})
