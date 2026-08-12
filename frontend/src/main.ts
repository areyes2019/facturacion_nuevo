import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import router from './router'

// Recarga ante chunk faltante (ver 011-precio-proveedor-utilidad.md). Los assets salen con hash de
// contenido y `dist/` se vacía en cada build, así que una pestaña abierta durante un despliegue
// puede quedarse con un index.html que apunta a chunks que ya no existen. En vez de romperse al
// navegar, se recarga una sola vez para tomar el index.html nuevo.
const CLAVE_RECARGA = 'recarga-por-chunk-faltante'

window.addEventListener('vite:preloadError', (event) => {
  if (sessionStorage.getItem(CLAVE_RECARGA)) return

  event.preventDefault()
  sessionStorage.setItem(CLAVE_RECARGA, '1')
  window.location.reload()
})

router.isReady().then(() => sessionStorage.removeItem(CLAVE_RECARGA))

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')
