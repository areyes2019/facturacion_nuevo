/**
 * Caducidad de cotizaciones (ver 008-cotizaciones.md): el backend manda `caduca_el` —la fecha en
 * que el borrado automático se la llevaría, o null si no caduca— y el frontend solo decide cuándo
 * vale la pena avisar. El plazo de 30 días no se repite aquí: llega ya resuelto en esa fecha.
 */

/** A partir de cuántos días restantes se avisa en el listado y en el detalle. */
export const DIAS_AVISO_CADUCIDAD = 7

/** Días completos que faltan para la fecha; 0 el mismo día, negativo si ya pasó. */
export function diasParaCaducar(caducaEl: string | null): number | null {
  if (caducaEl === null) return null

  const fecha = new Date(caducaEl)
  if (Number.isNaN(fecha.getTime())) return null

  const inicioDe = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const milisegundosPorDia = 24 * 60 * 60 * 1000

  return Math.round((inicioDe(fecha) - inicioDe(new Date())) / milisegundosPorDia)
}

export function caducaPronto(caducaEl: string | null): boolean {
  const dias = diasParaCaducar(caducaEl)

  return dias !== null && dias <= DIAS_AVISO_CADUCIDAD
}

/** "Se elimina hoy" / "Se elimina mañana" / "Se elimina en 5 días". */
export function textoCaducidad(caducaEl: string | null): string {
  const dias = diasParaCaducar(caducaEl) ?? 0

  if (dias <= 0) return 'Se elimina hoy'
  if (dias === 1) return 'Se elimina mañana'

  return `Se elimina en ${dias} días`
}

export function fechaLegible(fecha: string): string {
  return new Date(fecha).toLocaleDateString('es-MX', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}
