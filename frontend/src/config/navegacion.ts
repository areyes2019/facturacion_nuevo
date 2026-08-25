import type { FunctionalComponent } from 'vue'
import {
  ArchiveBoxIcon,
  ArrowsRightLeftIcon,
  BanknotesIcon,
  ChartBarIcon,
  ArrowRightStartOnRectangleIcon,
  ClipboardDocumentListIcon,
  Cog6ToothIcon,
  CubeIcon,
  DocumentDuplicateIcon,
  DocumentTextIcon,
  RectangleStackIcon,
  ScaleIcon,
  ShoppingCartIcon,
  TagIcon,
  TicketIcon,
  TruckIcon,
  UserCircleIcon,
  UsersIcon,
  WalletIcon,
  WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline'

/**
 * Definición declarativa de la navegación principal (spec 013).
 *
 * Esta es la única fuente de verdad del menú: `AppLayout.vue` solo la recorre.
 * Toda funcionalidad nueva que necesite entrada de menú se agrega acá — no como
 * un enlace suelto en el header. El reparto sigue el flujo comercial:
 *
 *   - Pantallas de negocio  → uno de los cuatro grupos de la barra.
 *   - Pantallas de configuración del sistema o de la cuenta → menú de usuario.
 */

export interface OpcionNavegacion {
  /** `name` de la ruta a la que navega la opción. */
  name: string
  etiqueta: string
  icono: FunctionalComponent
  /**
   * `name` de las rutas hermanas (crear / editar / detalle) que no aparecen en
   * el menú pero mantienen resaltados la opción y su grupo.
   */
  rutasRelacionadas?: string[]
}

export interface GrupoNavegacion {
  /** Identificador estable del grupo; es el `value` del NavigationMenuItem. */
  id: string
  etiqueta: string
  icono: FunctionalComponent
  opciones: OpcionNavegacion[]
}

export const gruposNavegacion: GrupoNavegacion[] = [
  {
    id: 'ventas',
    etiqueta: 'Ventas',
    icono: BanknotesIcon,
    opciones: [
      {
        // Primero en el grupo porque es la venta que entra por la puerta (ver 027). La etiqueta y
        // la pantalla de entrega no aparecen en el menú: a una se llega desde el detalle y a la
        // otra desde el QR impreso.
        name: 'pedidos',
        etiqueta: 'Pedidos',
        icono: TicketIcon,
        rutasRelacionadas: [
          'pedidos-crear',
          'pedidos-editar',
          'pedidos-detalle',
          'pedidos-etiqueta',
          'pedidos-entregar',
        ],
      },
      {
        name: 'facturas',
        etiqueta: 'Facturas',
        icono: DocumentTextIcon,
        rutasRelacionadas: ['facturas-crear', 'facturas-editar', 'facturas-detalle'],
      },
      {
        name: 'cotizaciones',
        etiqueta: 'Cotizaciones',
        icono: DocumentDuplicateIcon,
        rutasRelacionadas: ['cotizaciones-crear', 'cotizaciones-editar', 'cotizaciones-detalle'],
      },
      {
        name: 'clientes',
        etiqueta: 'Clientes',
        icono: UsersIcon,
        rutasRelacionadas: ['clientes-crear', 'clientes-editar'],
      },
    ],
  },
  {
    // Grupo propio y no dentro de "Ventas": una Orden de Trabajo puede colgar tanto de un Pedido
    // como de una Cotización, así que forzarla dentro de uno de los dos grupos existentes
    // escondería la mitad de los casos (ver 038-produccion-ordenes-trabajo.md).
    id: 'produccion',
    etiqueta: 'Producción',
    icono: WrenchScrewdriverIcon,
    opciones: [
      {
        name: 'produccion',
        etiqueta: 'Producción',
        icono: WrenchScrewdriverIcon,
        rutasRelacionadas: ['produccion-detalle'],
      },
    ],
  },
  {
    id: 'compras',
    etiqueta: 'Compras',
    icono: ShoppingCartIcon,
    opciones: [
      {
        name: 'ordenes-compra',
        etiqueta: 'Órdenes de compra',
        icono: ClipboardDocumentListIcon,
        rutasRelacionadas: [
          'ordenes-compra-crear',
          'ordenes-compra-editar',
          'ordenes-compra-detalle',
        ],
      },
      {
        name: 'proveedores',
        etiqueta: 'Proveedores',
        icono: TruckIcon,
        rutasRelacionadas: ['proveedores-crear', 'proveedores-editar'],
      },
    ],
  },
  {
    id: 'inventario',
    etiqueta: 'Inventario',
    icono: CubeIcon,
    opciones: [
      {
        name: 'articulos',
        etiqueta: 'Artículos',
        icono: TagIcon,
        rutasRelacionadas: ['articulos-crear', 'articulos-editar'],
      },
      {
        name: 'catalogos',
        etiqueta: 'Catálogos',
        icono: RectangleStackIcon,
        rutasRelacionadas: ['catalogos-crear', 'catalogos-editar'],
      },
      {
        // Artículos son los datos maestros (costos, precios); Existencias es la operación diaria
        // (cuánto hay, qué reponer). Ver 017-inventario.md.
        name: 'existencias',
        etiqueta: 'Existencias',
        icono: ArchiveBoxIcon,
        rutasRelacionadas: ['existencias-movimientos'],
      },
    ],
  },
  {
    // El nombre visible es "Contabilidad"; el módulo, sus rutas (/tesoreria/...)
    // y sus clases siguen llamándose Tesorería (ver 010-tesoreria.md).
    id: 'contabilidad',
    etiqueta: 'Contabilidad',
    icono: ChartBarIcon,
    opciones: [
      {
        name: 'cuentas',
        etiqueta: 'Cuentas',
        icono: WalletIcon,
        rutasRelacionadas: ['cuentas-crear', 'cuentas-editar'],
      },
      { name: 'movimientos', etiqueta: 'Movimientos', icono: ArrowsRightLeftIcon },
      { name: 'saldos', etiqueta: 'Saldos', icono: ScaleIcon },
    ],
  },
]

/**
 * Opciones del menú de usuario, al extremo derecho de la barra.
 *
 * No son un quinto grupo: los cuatro grupos son el flujo comercial del negocio
 * y la configuración del sistema no lo es. Aquí caen también las pantallas de
 * cuenta (perfil, contraseña) cuando existan.
 */
export const opcionesMenuUsuario: OpcionNavegacion[] = [
  { name: 'configuracion', etiqueta: 'Configuración', icono: Cog6ToothIcon },
]

/** Ícono de la acción de cerrar sesión, última entrada del menú de usuario. */
export const iconoCerrarSesion = ArrowRightStartOnRectangleIcon

/** Ícono del disparador del menú de usuario, y su reemplazo por debajo de `sm`. */
export const iconoUsuario = UserCircleIcon

/** Todos los `name` de ruta que mantienen resaltada a una opción. */
export function nombresDeRutaDeOpcion(opcion: OpcionNavegacion): string[] {
  return [opcion.name, ...(opcion.rutasRelacionadas ?? [])]
}

/** Todos los `name` de ruta que mantienen resaltado a un grupo. */
export function nombresDeRutaDeGrupo(grupo: GrupoNavegacion): string[] {
  return grupo.opciones.flatMap(nombresDeRutaDeOpcion)
}
