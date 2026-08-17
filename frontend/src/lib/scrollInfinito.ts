import { onBeforeUnmount, watch, type Ref } from 'vue'

/**
 * Carga la página siguiente cuando el final de la lista entra en pantalla (ver
 * 029-pwa-mostrador.md, adición técnica 64).
 *
 * Se hace con `IntersectionObserver`, que ya trae el navegador, en vez de con una librería: es un
 * observador sobre un solo elemento y no hay nada que una dependencia resolvería mejor.
 *
 * Recibe el `ref` del centinela —un elemento vacío al final de la lista, que la vista obtiene con
 * `useTemplateRef`—. El observador se conecta cuando ese elemento aparece y se suelta cuando
 * desaparece, así que esconderlo mientras se está cargando, o cuando ya no hay más páginas, basta
 * para que deje de pedir.
 */
export function useScrollInfinito(
  centinela: Ref<HTMLElement | null>,
  alLlegarAlFinal: () => void,
): void {
  let observador: IntersectionObserver | null = null

  function soltar() {
    observador?.disconnect()
    observador = null
  }

  watch(centinela, (elemento) => {
    soltar()

    // En un entorno sin `IntersectionObserver` —jsdom, por ejemplo— la lista sigue funcionando con
    // su primera página en vez de romperse al montar.
    if (elemento === null || typeof IntersectionObserver === 'undefined') return

    observador = new IntersectionObserver((entradas) => {
      if (entradas.some((entrada) => entrada.isIntersecting)) alLlegarAlFinal()
    })

    observador.observe(elemento)
  })

  onBeforeUnmount(soltar)
}
