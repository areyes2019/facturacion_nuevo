import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    tailwindcss(),
    // Hace la aplicación instalable ("Instalar" en Chrome/Edge, ventana propia sin barra de
    // direcciones). Requiere HTTPS en el servidor: fuera de localhost el navegador no registra
    // el service worker ni ofrece la instalación.
    VitePWA({
      // El service worker se actualiza solo cuando cambia el build. La alternativa ('prompt')
      // obliga a construir UI para avisar al usuario; acá no hace falta.
      registerType: 'autoUpdate',
      injectRegister: 'auto',
      manifest: {
        name: 'Facturación',
        short_name: 'Facturación',
        description: 'Sistema de facturación: ventas, compras, inventario y contabilidad.',
        lang: 'es',
        start_url: '/',
        scope: '/',
        display: 'standalone',
        theme_color: '#863bff',
        background_color: '#ffffff',
        icons: [
          { src: '/pwa-192x192.png', sizes: '192x192', type: 'image/png' },
          { src: '/pwa-512x512.png', sizes: '512x512', type: 'image/png' },
          {
            // Android recorta el icono en círculo; este lleva el logo dentro de la zona segura.
            src: '/pwa-maskable-512x512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable',
          },
        ],
      },
      workbox: {
        // Solo precache del shell. Deliberadamente sin `runtimeCaching`: cachear respuestas
        // autenticadas del API es la forma clásica de servir datos viejos —o de otro usuario—
        // desde el disco. Los datos siempre salen de la red.
        // Los iconos del manifest los agrega el plugin al precache por su cuenta; acá solo va
        // el shell. apple-touch-icon.png queda fuera a propósito: no está en el manifest y lo
        // pide iOS al instalar, no la aplicación en marcha.
        globPatterns: ['**/*.{js,css,html,svg}'],
        // La SPA resuelve sus rutas en el cliente, así que cualquier navegación devuelve el
        // índice; las rutas del backend quedan excluidas para que nunca se respondan con HTML.
        // En producción comparte origen con Laravel (ver 018-despliegue-hostinger.md), así que la
        // lista cubre las tres rutas que le pertenecen: el API, la cookie CSRF y el healthcheck.
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [/^\/api\//, /^\/sanctum\//, /^\/up$/],
      },
    }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  // Alcance deliberadamente mínimo (ver 011-precio-proveedor-utilidad.md): solo el módulo de
  // cálculo de precios, que es aritmética pura y no necesita jsdom ni montar componentes.
  test: {
    include: ['src/lib/**/*.test.ts'],
    environment: 'node',
  },
})
