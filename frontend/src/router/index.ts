import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { rutaBloqueadaEnMostrador } from '../lib/modoMostrador'

declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean
    guestOnly?: boolean
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', redirect: { name: 'dashboard' } },
    {
      path: '/login',
      name: 'login',
      component: () => import('../views/LoginView.vue'),
      meta: { guestOnly: true },
    },
    {
      path: '/forgot-password',
      name: 'forgot-password',
      component: () => import('../views/ForgotPasswordView.vue'),
      meta: { guestOnly: true },
    },
    {
      path: '/reset-password',
      name: 'reset-password',
      component: () => import('../views/ResetPasswordView.vue'),
      meta: { guestOnly: true },
    },
    {
      path: '/dashboard',
      name: 'dashboard',
      component: () => import('../views/DashboardView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/clientes',
      name: 'clientes',
      component: () => import('../views/ClientesListView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/clientes/crear',
      name: 'clientes-crear',
      component: () => import('../views/ClienteFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/clientes/:id/editar',
      name: 'clientes-editar',
      component: () => import('../views/ClienteFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/proveedores',
      name: 'proveedores',
      component: () => import('../views/ProveedoresListView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/proveedores/crear',
      name: 'proveedores-crear',
      component: () => import('../views/ProveedorFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/proveedores/:id/editar',
      name: 'proveedores-editar',
      component: () => import('../views/ProveedorFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/catalogos',
      name: 'catalogos',
      component: () => import('../views/CatalogosListView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/catalogos/crear',
      name: 'catalogos-crear',
      component: () => import('../views/CatalogoFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/catalogos/:id/editar',
      name: 'catalogos-editar',
      component: () => import('../views/CatalogoFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      // Existencias, no "Inventario": el grupo del menú ya se llama Inventario y agrupa Artículos,
      // Catálogos y esta pantalla (ver 017-inventario.md).
      path: '/existencias',
      name: 'existencias',
      component: () => import('../views/ExistenciasListView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/existencias/:id/movimientos',
      name: 'existencias-movimientos',
      component: () => import('../views/ExistenciaMovimientosView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/articulos',
      name: 'articulos',
      component: () => import('../views/ArticulosListView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/articulos/crear',
      name: 'articulos-crear',
      component: () => import('../views/ArticuloFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/articulos/:id/editar',
      name: 'articulos-editar',
      component: () => import('../views/ArticuloFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/facturas',
      name: 'facturas',
      component: () => import('../views/FacturasListView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/facturas/crear',
      name: 'facturas-crear',
      component: () => import('../views/FacturaFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/facturas/:id/editar',
      name: 'facturas-editar',
      component: () => import('../views/FacturaFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/facturas/:id',
      name: 'facturas-detalle',
      component: () => import('../views/FacturaDetalleView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/cotizaciones',
      name: 'cotizaciones',
      component: () => import('../views/CotizacionesListView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/cotizaciones/crear',
      name: 'cotizaciones-crear',
      component: () => import('../views/CotizacionFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/cotizaciones/:id/editar',
      name: 'cotizaciones-editar',
      component: () => import('../views/CotizacionFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      // Vista de impresión de la etiqueta adhesiva, sin layout de la aplicación (ver
      // 038-produccion-ordenes-trabajo.md, mismo patrón que pedidos-etiqueta de 027).
      path: '/cotizaciones/:id/etiqueta',
      name: 'cotizaciones-etiqueta',
      component: () => import('../views/CotizacionEtiquetaView.vue'),
      meta: { requiresAuth: true },
    },
    {
      // Destino del QR de la etiqueta (ver 038, mismo patrón que pedidos-entregar de 027).
      path: '/cotizaciones/:id/entregar',
      name: 'cotizaciones-entregar',
      component: () => import('../views/CotizacionEntregaView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/cotizaciones/:id',
      name: 'cotizaciones-detalle',
      component: () => import('../views/CotizacionDetalleView.vue'),
      meta: { requiresAuth: true },
    },
    // Venta de mostrador (ver 027-venta-mostrador-ticket.md).
    {
      path: '/pedidos',
      name: 'pedidos',
      component: () => import('../views/PedidosListView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/pedidos/crear',
      name: 'pedidos-crear',
      component: () => import('../views/PedidoFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/pedidos/:id/editar',
      name: 'pedidos-editar',
      component: () => import('../views/PedidoFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      // Vista de impresión de la etiqueta adhesiva: sin layout de la aplicación, porque lo que se
      // manda a la impresora son 5 × 2.5 cm y nada más.
      path: '/pedidos/:id/etiqueta',
      name: 'pedidos-etiqueta',
      component: () => import('../views/PedidoEtiquetaView.vue'),
      meta: { requiresAuth: true },
    },
    {
      // Destino del QR de la etiqueta. Requiere sesión: es una acción que mueve dinero, y quien
      // encuentre una etiqueta tirada no debe poder cerrarla.
      path: '/pedidos/:id/entregar',
      name: 'pedidos-entregar',
      component: () => import('../views/PedidoEntregaView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/pedidos/:id',
      name: 'pedidos-detalle',
      component: () => import('../views/PedidoDetalleView.vue'),
      meta: { requiresAuth: true },
    },
    // Producción: tablero de Órdenes de Trabajo (ver 038-produccion-ordenes-trabajo.md).
    {
      path: '/produccion',
      name: 'produccion',
      component: () => import('../views/ProduccionListView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/produccion/:id',
      name: 'produccion-detalle',
      component: () => import('../views/OrdenTrabajoDetalleView.vue'),
      meta: { requiresAuth: true },
    },
    // Captura por pasos del modo mostrador (ver 029-pwa-mostrador.md). Van bajo un prefijo común
    // para que el candado del guard sea una sola regla y no una lista que haya que recordar
    // ampliar. **No son una puerta de entrada**: nadie las escribe ni las guarda; se llega a ellas
    // tocando los cuatro accesos.
    {
      path: '/mostrador/venta',
      name: 'mostrador-venta',
      component: () => import('../views/mostrador/MostradorVentaView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/mostrador/factura',
      name: 'mostrador-factura',
      component: () => import('../views/mostrador/MostradorFacturaView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/mostrador/cotizacion',
      name: 'mostrador-cotizacion',
      component: () => import('../views/mostrador/MostradorCotizacionView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/mostrador/escanear',
      name: 'mostrador-escanear',
      component: () => import('../views/mostrador/MostradorEscanearView.vue'),
      meta: { requiresAuth: true },
    },
    // Las tres secciones de la barra del pie (ver 031-mostrador-consulta.md). Van bajo el mismo
    // prefijo por la misma razón que las cuatro de arriba, en plural para la lista y con sufijo
    // `-ver` para el detalle, para no chocar con los nombres de las rutas de captura.
    {
      path: '/mostrador/cotizaciones',
      name: 'mostrador-cotizaciones',
      component: () => import('../views/mostrador/MostradorCotizacionesView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/mostrador/cotizaciones/:id',
      name: 'mostrador-cotizacion-ver',
      component: () => import('../views/mostrador/MostradorCotizacionDetalleView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/mostrador/facturas',
      name: 'mostrador-facturas',
      component: () => import('../views/mostrador/MostradorFacturasView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/mostrador/facturas/:id',
      name: 'mostrador-factura-ver',
      component: () => import('../views/mostrador/MostradorFacturaDetalleView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/mostrador/catalogo',
      name: 'mostrador-catalogo',
      component: () => import('../views/mostrador/MostradorCatalogoView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/mostrador/catalogo/:id',
      name: 'mostrador-articulo-ver',
      component: () => import('../views/mostrador/MostradorArticuloView.vue'),
      meta: { requiresAuth: true },
    },
    {
      // Portal público de autofacturación: lo abre el cliente, que no tiene cuenta en el sistema.
      // Fuera del guard de sesión y sin el layout de la aplicación.
      path: '/autofactura/:token',
      name: 'autofactura',
      component: () => import('../views/AutofacturaView.vue'),
    },
    {
      path: '/ordenes-compra',
      name: 'ordenes-compra',
      component: () => import('../views/OrdenesCompraListView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/ordenes-compra/crear',
      name: 'ordenes-compra-crear',
      component: () => import('../views/OrdenCompraFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/ordenes-compra/:id/editar',
      name: 'ordenes-compra-editar',
      component: () => import('../views/OrdenCompraFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/ordenes-compra/:id',
      name: 'ordenes-compra-detalle',
      component: () => import('../views/OrdenCompraDetalleView.vue'),
      meta: { requiresAuth: true },
    },
    // Tesorería: el nombre técnico del módulo y sus rutas siguen siendo "tesoreria"; solo la
    // etiqueta del menú dice "Contabilidad" (ver 010-tesoreria.md).
    {
      path: '/tesoreria/cuentas',
      name: 'cuentas',
      component: () => import('../views/CuentasListView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/tesoreria/cuentas/crear',
      name: 'cuentas-crear',
      component: () => import('../views/CuentaFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/tesoreria/cuentas/:id/editar',
      name: 'cuentas-editar',
      component: () => import('../views/CuentaFormView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/tesoreria/movimientos',
      name: 'movimientos',
      component: () => import('../views/MovimientosListView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/tesoreria/saldos',
      name: 'saldos',
      component: () => import('../views/SaldosView.vue'),
      meta: { requiresAuth: true },
    },
    {
      // Pantalla de ajustes globales, alcanzable desde el menú de usuario (ver 013 y 014).
      // Es una sola pantalla con secciones: la tasa de IVA, los datos fiscales y los folios
      // entrarán como secciones hermanas, sin URL nueva.
      path: '/configuracion',
      name: 'configuracion',
      component: () => import('../views/ConfiguracionView.vue'),
      meta: { requiresAuth: true },
    },
    ...(import.meta.env.DEV
      ? [
          {
            path: '/design-system',
            name: 'design-system',
            component: () => import('../views/DesignSystemView.vue'),
          },
        ]
      : []),
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (!auth.initialized) {
    await auth.fetchUser()
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    // Se recuerda a dónde iba para volver ahí al autenticarse. Sin esto, escanear el QR de una
    // etiqueta desde un celular sin sesión abierta dejaría al usuario en el dashboard, teniendo que
    // buscar a mano el pedido que acababa de escanear (ver 027-venta-mostrador-ticket.md).
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: 'dashboard' }
  }

  // El candado del modo mostrador (ver 029-pwa-mostrador.md). "No se llega por ningún medio" no se
  // cumple escondiendo el menú: una dirección vieja guardada en el navegador, un enlace pegado o un
  // botón que quedó apuntando a una pantalla de escritorio terminan en los cuatro accesos, en vez
  // de mostrar media aplicación que ahí no se puede usar.
  if (rutaBloqueadaEnMostrador(String(to.name ?? ''))) {
    return { name: 'dashboard' }
  }

  return true
})

export default router
