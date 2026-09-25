import axios from 'axios'
import { createApp, nextTick } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/auth'
import { aplicarActualizacion } from './lib/actualizacion'
import { escucharInstalacion } from './lib/instalacion'
import { registrarSesionCaida } from './lib/http'

// El navegador dispara `beforeinstallprompt` muy pronto; hay que estar escuchando antes de montar
// nada o el aviso se pierde para el resto de la sesión (ver 029-pwa-mostrador.md).
escucharInstalacion()

// Recarga ante chunk faltante (ver 011-precio-proveedor-utilidad.md y
// 044-sesion-caida-y-pantallas-trabadas.md). Los assets salen con hash de contenido y `dist/` se
// vacía en cada build, así que una pestaña abierta durante un despliegue puede quedarse con un
// index.html que apunta a chunks que ya no existen.
const CLAVE_RECARGA = 'recarga-por-chunk-faltante'

window.addEventListener('vite:preloadError', (event) => {
  // Recargar solo sirve si puede traer otra versión. Si ya se recargó y seguimos en la misma
  // compilación, insistir es lo que deja la pantalla trabada recargando sin avanzar nunca.
  if (sessionStorage.getItem(CLAVE_RECARGA) === __BUILD_ID__) return

  event.preventDefault()
  sessionStorage.setItem(CLAVE_RECARGA, __BUILD_ID__)

  // Y no basta con `location.reload()`: el service worker seguiría sirviendo el mismo index.html
  // viejo de su precache. Hay que activar primero la versión que tenga esperando.
  void aplicarActualizacion()
})

/** Los navegadores no coinciden en el texto, pero todos dicen lo mismo de un módulo que no cargó. */
function esFalloDeChunk(error: unknown): boolean {
  const mensaje = error instanceof Error ? error.message : String(error)

  return /dynamically imported module|Importing a module script failed|error loading/i.test(mensaje)
}

/**
 * Última red: la pantalla no se va a poder dibujar. Se dibuja sin Vue y con estilos en línea a
 * propósito —la hoja de estilos puede ser justo lo que no cargó, o Vue lo que tronó—, porque lo
 * único peor que esta pantalla es la pantalla en blanco sin explicación que había antes.
 */
function mostrarPantallaQueNoCarga(mensaje: string): void {
  if (document.getElementById('pantalla-sin-cargar')) return

  const aviso = document.createElement('div')
  aviso.id = 'pantalla-sin-cargar'
  aviso.setAttribute('role', 'alert')
  aviso.setAttribute(
    'style',
    'position:fixed;inset:0;z-index:100;display:flex;flex-direction:column;' +
      'align-items:center;justify-content:center;gap:16px;padding:24px;text-align:center;' +
      'background:#ffffff;color:#0a0a0a;font-family:system-ui,sans-serif',
  )

  const texto = document.createElement('p')
  texto.textContent = mensaje
  texto.setAttribute('style', 'margin:0;font-size:16px')

  const boton = document.createElement('button')
  boton.type = 'button'
  boton.textContent = 'Recargar'
  boton.setAttribute(
    'style',
    'cursor:pointer;border:0;border-radius:6px;padding:10px 20px;' +
      'background:#863bff;color:#ffffff;font-size:14px;font-weight:600',
  )
  boton.addEventListener('click', () => window.location.reload())

  aviso.append(texto, boton)
  document.body.append(aviso)
}

router.onError((error) => {
  if (esFalloDeChunk(error))
    mostrarPantallaQueNoCarga('No se pudo cargar esta pantalla. Recarga la página.')
})

const app = createApp(App)

app.use(createPinia())
app.use(router)

// Un error al dibujar una vista la dejaba en blanco, con el error solo en la consola (ver
// 044-sesion-caida-y-pantallas-trabadas.md, causa 4). La pantalla de "Recargar" sale solo si de
// verdad no quedó nada: un error dentro de una celda o de un diálogo deja el resto usable, y taparlo
// sería peor. Los de axios no entran: un 401 lo resuelve el interceptor y los demás cada pantalla.
app.config.errorHandler = (error) => {
  // Definir el manejador le quita a Vue el registro en consola; sin él no habría qué diagnosticar.
  console.error(error)

  if (axios.isAxiosError(error)) return

  void nextTick(() => {
    if (!document.getElementById('app')?.innerText.trim()) {
      mostrarPantallaQueNoCarga('Ocurrió un error al mostrar esta pantalla. Recarga la página.')
    }
  })
}

// El interceptor de `http` avisa que el servidor ya no reconoce la sesión; qué hacer con eso se
// decide aquí, que es donde existen el router y el store (ver 044-sesion-caida-y-pantallas-trabadas.md).
registrarSesionCaida(() => {
  const ruta = router.currentRoute.value
  const auth = useAuthStore()

  // Solo hay algo que rescatar si la pantalla actual necesitaba sesión y creíamos tenerla. Esa sola
  // condición deja fuera el login, el arranque sin sesión y el portal público de autofacturación,
  // que lo abre un cliente sin cuenta.
  if (!ruta.meta.requiresAuth || !auth.isAuthenticated) return

  const destino = ruta.fullPath

  auth.marcarSesionCaida()

  // Si había una navegación en curso, el router rechaza esta: es un final aceptable —el guard
  // llevará al login de todos modos— y no algo que deba asomar en la consola.
  router
    .replace({ name: 'login', query: { redirect: destino, expirada: '1' } })
    .catch(() => undefined)
})

app.mount('#app')
