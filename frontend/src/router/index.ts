import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

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
      path: '/cotizaciones/:id',
      name: 'cotizaciones-detalle',
      component: () => import('../views/CotizacionDetalleView.vue'),
      meta: { requiresAuth: true },
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
    return { name: 'login' }
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: 'dashboard' }
  }

  return true
})

export default router
