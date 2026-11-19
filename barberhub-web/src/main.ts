import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { connectSession } from './stores/session'
import { usePreferencesStore } from './stores/preferences'
import './assets/styles/main.css'

const app = createApp(App)

app.use(createPinia())
// Takes over from the script in index.html: follows the device live and saves the choices.
usePreferencesStore()
app.use(router)
connectSession(router)

app.mount('#app')
