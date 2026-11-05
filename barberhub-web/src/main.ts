import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { connectSession } from './stores/session'
import './assets/styles/main.css'

const app = createApp(App)

app.use(createPinia())
app.use(router)
connectSession(router)

app.mount('#app')
