import { useArticulosStore } from '../stores/articulos'
import type { LineaEditable } from '../components/DocumentoLineas.vue'

/**
 * Reemplaza el precio unitario de cada línea por el que corresponda al cliente elegido (distribuidor
 * o directo), incluidas las líneas que el usuario ya había editado a mano (ver
 * 033-precio-distribuidor.md).
 *
 * Usa los precios ya cacheados en la línea (`precio_directo_sin_iva`/`precio_distribuidor_sin_iva`,
 * escritos por `DocumentoLineas` al agregarla desde el buscador) cuando existen. Una línea que llegó
 * de un documento ya guardado (edición) no los trae, así que se consulta el artículo en ese momento
 * — no al abrir el formulario — para no hacer N consultas en documentos que nunca cambian de
 * cliente.
 */
export async function aplicarPrecioCliente(
  lineas: LineaEditable[],
  esDistribuidor: boolean,
): Promise<LineaEditable[]> {
  const articulos = useArticulosStore()

  return Promise.all(
    lineas.map(async (linea) => {
      if (!linea.articulo_id) return linea

      let directo = linea.precio_directo_sin_iva
      let distribuidor = linea.precio_distribuidor_sin_iva

      if (directo === undefined || distribuidor === undefined) {
        const articulo = await articulos.fetchOne(linea.articulo_id)
        directo = articulo.precio_unitario_sin_iva
        distribuidor = articulo.precio_distribuidor_sin_iva
      }

      return {
        ...linea,
        precio_unitario: esDistribuidor ? distribuidor : directo,
        precio_directo_sin_iva: directo,
        precio_distribuidor_sin_iva: distribuidor,
      }
    }),
  )
}
