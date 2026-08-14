import { defineStore } from 'pinia'
import http, { API_URL } from '../lib/http'
import { extractErrorMessage } from '../lib/errors'

export interface Articulo {
  id: number
  catalogo_id: number
  catalogo_nombre: string | null
  proveedor_id: number | null
  proveedor_nombre_comercial: string | null
  nombre: string
  modelo: string
  clave_prod_serv: string
  clave_unidad: string
  objeto_imp: string
  precio_proveedor: number
  utilidad_porcentaje: number | null
  utilidad_porcentaje_efectivo: number | null
  tamano_goma: string | null
  tiene_imagen: boolean
  imagen_version: string | null
  costo_goma: number
  costo_con_descuento: number
  costo_total: number
  precio_unitario_sin_iva: number
  precio_unitario_con_iva: number
  utilidad: number
  created_at: string
  updated_at: string
}

export interface ArticuloPayload {
  catalogo_id: number | null
  nombre: string
  modelo: string
  clave_prod_serv: string | null
  clave_unidad: string | null
  objeto_imp: string | null
  precio_proveedor: number | null
  utilidad_porcentaje: number | null
  tamano_goma: string | null
}

export interface ImportarCsvReporte {
  importados: number
  /**
   * `modelo` es con lo que se emparejan las imágenes (ver 020), así que es el dato que conecta una
   * fila rechazada con la foto que se quedará sin artículo. Va `null` cuando la fila no traía ni
   * modelo ni nombre (ver 023-carga-masiva-por-pasos.md).
   */
  errores: { fila: number; modelo: string | null; motivo: string }[]
}

/**
 * Reporte de la carga masiva de imágenes (ver 020-imagenes-articulos.md). Misma forma que el del
 * CSV, cambiando `fila` por `archivo`: aquí lo que identifica al rechazado es su nombre.
 */
export interface ImagenesReporte {
  asociadas: number
  errores: { archivo: string; motivo: string }[]
}

/**
 * Máximo de archivos por tanda. Es `max_file_uploads` de PHP, cuyo valor por defecto es 20: PHP
 * **descarta en silencio** los archivos que pasen de ese número, sin error y sin que el reporte lo
 * mencione, así que una tanda más grande perdería fotos sin que nadie se entere.
 */
const MAXIMO_POR_TANDA = 20

/** Peso máximo por tanda, por debajo del `post_max_size` de un plan compartido. */
const MAXIMO_BYTES_POR_TANDA = 40 * 1024 * 1024

/**
 * URL de la imagen de un artículo, o `null` si no tiene.
 *
 * Lleva colgada `imagen_version`, que cambia con cada reemplazo: sin ese parámetro el navegador
 * seguiría mostrando la foto anterior, porque la URL sería idéntica y él no tiene forma de saber
 * que el archivo detrás cambió.
 */
export function imagenUrl(articulo: Articulo): string | null {
  if (!articulo.tiene_imagen) return null

  return `${API_URL}/articulos/${articulo.id}/imagen?v=${articulo.imagen_version ?? ''}`
}

/**
 * Parte una selección de archivos en tandas que PHP sí acepta: por número de archivos y por peso,
 * lo que se alcance primero.
 */
function partirEnTandas(archivos: File[]): File[][] {
  const tandas: File[][] = []
  let actual: File[] = []
  let bytes = 0

  for (const archivo of archivos) {
    if (
      actual.length >= MAXIMO_POR_TANDA ||
      (actual.length > 0 && bytes + archivo.size > MAXIMO_BYTES_POR_TANDA)
    ) {
      tandas.push(actual)
      actual = []
      bytes = 0
    }

    actual.push(archivo)
    bytes += archivo.size
  }

  if (actual.length > 0) tandas.push(actual)

  return tandas
}

interface PaginationMeta {
  current_page: number
  last_page: number
  total: number
}

/**
 * Columnas numéricas ordenables del listado (ver 011-precio-proveedor-utilidad.md). El costo que se
 * muestra y por el que se ordena es el total, aparato + goma (ver 014-costo-elaboracion-goma.md).
 *
 * `id` es el orden de captura: ascendente devuelve los artículos en el orden en que se crearon, que
 * para una importación masiva es el orden de las filas del archivo
 * (ver 025-filtros-columna-listado-articulos.md).
 */
export type ArticuloSort = 'id' | 'costo_total' | 'precio_unitario_sin_iva' | 'utilidad'
export type SortDirection = 'asc' | 'desc'

/**
 * Filtros de la fila de cabeceras del listado (ver 025-filtros-columna-listado-articulos.md).
 *
 * Van como texto y no como número porque vienen de campos donde la cadena vacía es un estado
 * legítimo —"este filtro no está puesto"— y `0` es un valor que sí se puede querer filtrar. El
 * catálogo es la excepción: sale de un selector, no de un campo.
 */
export interface ArticuloFiltros {
  id: string
  nombre: string
  modelo: string
  catalogoId: number | null
  costoMin: string
  costoMax: string
  precioMin: string
  precioMax: string
  utilidadMin: string
  utilidadMax: string
}

/** Los filtros que se teclean, que son todos menos el catálogo: los de texto y los de rango. */
export type ArticuloFiltroTexto = Exclude<keyof ArticuloFiltros, 'catalogoId'>

export function filtrosVacios(): ArticuloFiltros {
  return {
    id: '',
    nombre: '',
    modelo: '',
    catalogoId: null,
    costoMin: '',
    costoMax: '',
    precioMin: '',
    precioMax: '',
    utilidadMin: '',
    utilidadMax: '',
  }
}

export const useArticulosStore = defineStore('articulos', {
  state: () => ({
    items: [] as Articulo[],
    meta: null as PaginationMeta | null,
    search: '',
    filtros: filtrosVacios(),
    sort: null as ArticuloSort | null,
    direction: 'asc' as SortDirection,
    loading: false,
    error: null as string | null,
  }),

  getters: {
    /**
     * Si hay algún filtro de columna puesto. El buscador global no cuenta: tiene su propia caja y su
     * propia forma de vaciarse (ver 025-filtros-columna-listado-articulos.md).
     */
    hayFiltros(state): boolean {
      return (
        state.filtros.catalogoId !== null ||
        (Object.entries(state.filtros) as [keyof ArticuloFiltros, unknown][]).some(
          ([clave, valor]) => clave !== 'catalogoId' && valor !== '',
        )
      )
    },
  },

  actions: {
    /**
     * Los parámetros con los que se pide el listado. Los comparte con la exportación CSV para que
     * exportar baje exactamente lo que la tabla está mostrando
     * (ver 025-filtros-columna-listado-articulos.md).
     */
    paramsListado(): Record<string, string | number | undefined> {
      const { filtros } = this

      return {
        search: this.search || undefined,
        sort: this.sort ?? undefined,
        direction: this.sort ? this.direction : undefined,
        filtro_id: filtros.id || undefined,
        filtro_nombre: filtros.nombre || undefined,
        filtro_modelo: filtros.modelo || undefined,
        filtro_catalogo_id: filtros.catalogoId ?? undefined,
        costo_min: filtros.costoMin || undefined,
        costo_max: filtros.costoMax || undefined,
        precio_min: filtros.precioMin || undefined,
        precio_max: filtros.precioMax || undefined,
        utilidad_min: filtros.utilidadMin || undefined,
        utilidad_max: filtros.utilidadMax || undefined,
      }
    },

    /** Borra los filtros de columna y recarga. El buscador global se queda como está. */
    async limpiarFiltros() {
      this.filtros = filtrosVacios()
      await this.fetchList(1)
    },

    async fetchList(page = 1) {
      this.loading = true
      this.error = null

      try {
        const { data } = await http.get('/articulos', {
          params: { page, ...this.paramsListado() },
        })
        this.items = data.data
        this.meta = data.meta
      } catch (err) {
        this.error = extractErrorMessage(err)
        throw err
      } finally {
        this.loading = false
      }
    },

    async fetchOne(id: number): Promise<Articulo> {
      const { data } = await http.get(`/articulos/${id}`)
      return data.data
    },

    async create(payload: ArticuloPayload): Promise<Articulo> {
      const { data } = await http.post('/articulos', payload)
      return data.data
    },

    async update(id: number, payload: ArticuloPayload): Promise<Articulo> {
      const { data } = await http.put(`/articulos/${id}`, payload)
      return data.data
    },

    async remove(id: number) {
      await http.delete(`/articulos/${id}`)
      this.items = this.items.filter((articulo) => articulo.id !== id)
    },

    /**
     * Borrado en lote (ver 021-mantenimiento-articulos-catalogos.md).
     *
     * Va en **una sola petición** con la lista completa: con una petición por artículo, una conexión
     * que se cae a la mitad dejaría un subconjunto arbitrario borrado y sin forma de saber cuál.
     */
    async removeLote(ids: number[]): Promise<number> {
      const { data } = await http.post('/articulos/eliminar-lote', { ids })
      this.items = this.items.filter((articulo) => !ids.includes(articulo.id))

      return data.eliminados
    },

    async importarCsv(catalogoId: number, archivo: File): Promise<ImportarCsvReporte> {
      const formData = new FormData()
      formData.append('archivo', archivo)

      const { data } = await http.post(
        `/catalogos-proveedor/${catalogoId}/articulos/importar-csv`,
        formData,
      )
      return data
    },

    async subirImagen(id: number, archivo: File): Promise<Articulo> {
      const formData = new FormData()
      formData.append('archivo', archivo)

      const { data } = await http.post(`/articulos/${id}/imagen`, formData)
      this.reemplazarEnListado(data.data)

      return data.data
    },

    async eliminarImagen(id: number): Promise<void> {
      await http.delete(`/articulos/${id}/imagen`)

      const articulo = this.items.find((item) => item.id === id)
      if (articulo) {
        articulo.tiene_imagen = false
        articulo.imagen_version = null
      }
    },

    /**
     * Carga masiva de imágenes contra un catálogo.
     *
     * La selección múltiple se manda en tandas —PHP no acepta más de 20 archivos por petición— y
     * los reportes de todas se suman en uno solo: el usuario eligió una vez y espera un resultado,
     * no quince. Un ZIP viaja entero en una sola petición, porque no se puede partir.
     */
    async cargarImagenes(
      catalogoId: number,
      archivos: File[],
      onProgreso?: (enviados: number, total: number) => void,
    ): Promise<ImagenesReporte> {
      const esZip = archivos.length === 1 && /\.zip$/i.test(archivos[0].name)
      const tandas = esZip ? [archivos] : partirEnTandas(archivos)

      const reporte: ImagenesReporte = { asociadas: 0, errores: [] }
      let enviados = 0

      for (const tanda of tandas) {
        const formData = new FormData()

        if (esZip) {
          formData.append('archivo', tanda[0])
        } else {
          tanda.forEach((archivo) => formData.append('archivos[]', archivo))
        }

        const { data } = await http.post(
          `/catalogos-proveedor/${catalogoId}/articulos/imagenes`,
          formData,
        )

        reporte.asociadas += data.asociadas
        reporte.errores.push(...data.errores)

        enviados += tanda.length
        onProgreso?.(enviados, archivos.length)
      }

      return reporte
    },

    /** Refresca en el listado la copia del artículo que acaba de cambiar. */
    reemplazarEnListado(articulo: Articulo) {
      const indice = this.items.findIndex((item) => item.id === articulo.id)
      if (indice !== -1) this.items[indice] = articulo
    },

    /** Alterna la ordenación de una columna: asc -> desc -> sin ordenar. */
    async toggleSort(columna: ArticuloSort) {
      if (this.sort !== columna) {
        this.sort = columna
        this.direction = 'asc'
      } else if (this.direction === 'asc') {
        this.direction = 'desc'
      } else {
        this.sort = null
        this.direction = 'asc'
      }

      await this.fetchList(1)
    },

    async exportarCsv() {
      const { data } = await http.get('/articulos/exportar-csv', {
        params: this.paramsListado(),
        responseType: 'blob',
      })

      const url = URL.createObjectURL(new Blob([data], { type: 'text/csv' }))
      const enlace = document.createElement('a')
      enlace.href = url
      enlace.download = 'articulos.csv'
      enlace.click()
      URL.revokeObjectURL(url)
    },
  },
})
