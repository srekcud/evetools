import { createApp } from 'vue'
import { createPinia } from 'pinia'
import router from './router'
import { i18n } from './i18n'
import App from './App.vue'
import './style.css'
import { useAuthStore } from './stores/auth'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(i18n)
app.use(router)

// Initialize auth store from localStorage
const authStore = useAuthStore()
authStore.init().then(() => {
  app.mount('#app')
}).catch(() => {
  app.mount('#app')
})
