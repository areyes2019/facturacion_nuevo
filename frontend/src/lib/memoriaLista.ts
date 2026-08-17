/**
 * Memoria de posición de las listas del mostrador (ver 031-mostrador-consulta.md).
 *
 * Al volver de un detalle, la lista aparece **donde la dejaste**: con lo que ya se había cargado,
 * con lo que estaba escrito en el buscador y a la altura en la que ibas. Reabrirla desde cero
 * después de mirar una cotización obligaría a recorrer otra vez todo lo recorrido, y revisar varias
 * seguidas es justo lo que se hace en esas pantallas.
 *
 * Vive **en memoria** y se olvida al cerrar sesión: nada del sistema se escribe en el aparato, misma
 * regla con la que 029 dejó fuera el precache de respuestas autenticadas.
 */

export interface ListaRecordada<T> {
  items: T[]
  pagina: number
  ultimaPagina: number
  texto: string
  scrollY: number
}

const MEMORIA = new Map<string, ListaRecordada<unknown>>()

export function recordarLista<T>(clave: string, estado: ListaRecordada<T>): void {
  MEMORIA.set(clave, estado as ListaRecordada<unknown>)
}

export function listaRecordada<T>(clave: string): ListaRecordada<T> | null {
  return (MEMORIA.get(clave) as ListaRecordada<T> | undefined) ?? null
}

export function olvidarListas(): void {
  MEMORIA.clear()
}
