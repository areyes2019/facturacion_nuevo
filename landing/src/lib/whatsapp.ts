export const WHATSAPP_NUMERO = '524613581090'

export type OrigenWhatsapp = 'general' | 'producto' | 'distribuidor' | 'imprenta'

const MENSAJES: Record<OrigenWhatsapp, string> = {
  general: 'Hola, quiero información sobre sus productos.',
  producto: 'Hola, me interesa información sobre sus sellos e insumos.',
  distribuidor: 'Hola, me interesa conocer información para trabajar como distribuidor.',
  imprenta: 'Hola, tengo una imprenta y me interesa conocer sus productos.',
}

export function enlaceWhatsapp(origen: OrigenWhatsapp = 'general'): string {
  return `https://wa.me/${WHATSAPP_NUMERO}?text=${encodeURIComponent(MENSAJES[origen])}`
}
