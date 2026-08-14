<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Bars3Icon, ChevronDownIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { useAuthStore } from '../stores/auth'
import { Button } from '../components/ui/button'
import {
  NavigationMenu,
  NavigationMenuContent,
  NavigationMenuItem,
  NavigationMenuLink,
  NavigationMenuList,
  NavigationMenuTrigger,
} from '../components/ui/navigation-menu'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '../components/ui/dropdown-menu'
import {
  gruposNavegacion,
  iconoCerrarSesion,
  iconoUsuario,
  nombresDeRutaDeGrupo,
  nombresDeRutaDeOpcion,
  opcionesMenuUsuario,
  type GrupoNavegacion,
  type OpcionNavegacion,
} from '../config/navegacion'

/**
 * Ancho del contenedor de la página.
 *
 * `normal` es el de siempre y sirve a casi todo: formularios y prosa se leen mal a lo ancho de una
 * pantalla completa. `amplio` existe para los listados densos, donde el ancho no es estética sino la
 * diferencia entre ver la tabla completa y tener que arrastrar una barra
 * (ver 025-filtros-columna-listado-articulos.md).
 *
 * La clase se aplica a la barra superior, al menú móvil y al `<main>` a la vez: si solo se ensanchara
 * el contenido, la tabla saldría descuadrada respecto del encabezado que tiene encima.
 */
const props = withDefaults(defineProps<{ ancho?: 'normal' | 'amplio' }>(), { ancho: 'normal' })

const anchoContenedor = computed(() => (props.ancho === 'amplio' ? 'max-w-[96rem]' : 'max-w-5xl'))

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

/** Grupo abierto en el menú de escritorio ('' = ninguno). */
const grupoAbierto = ref('')
/** Panel móvil desplegado. */
const menuMovilAbierto = ref(false)
/** Grupo expandido dentro del acordeón móvil ('' = ninguno). */
const grupoMovilAbierto = ref('')

// El resaltado se resuelve por `name` de ruta, no por prefijo de path: los
// grupos no comparten prefijo (Ventas abarca /facturas, /cotizaciones y
// /clientes), así que un `startsWith` no se generaliza.
const nombreRutaActual = computed(() => String(route.name ?? ''))

function grupoActivo(grupo: GrupoNavegacion) {
  return nombresDeRutaDeGrupo(grupo).includes(nombreRutaActual.value)
}

function opcionActiva(opcion: OpcionNavegacion) {
  return nombresDeRutaDeOpcion(opcion).includes(nombreRutaActual.value)
}

// El menú de usuario se resalta con la misma regla que un grupo: cuando la ruta
// actual pertenece a alguna de sus opciones.
const menuUsuarioActivo = computed(() => opcionesMenuUsuario.some((opcion) => opcionActiva(opcion)))

const nombreUsuario = computed(() => auth.user?.name ?? '')

// Cerrar todo al navegar: el desplegable de escritorio, el panel móvil y su
// acordeón.
watch(
  () => route.fullPath,
  () => {
    grupoAbierto.value = ''
    menuMovilAbierto.value = false
    grupoMovilAbierto.value = ''
  },
)

function alternarGrupoMovil(id: string) {
  grupoMovilAbierto.value = grupoMovilAbierto.value === id ? '' : id
}

async function onLogout() {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <div class="bg-muted min-h-screen">
    <header class="bg-background border-border border-b">
      <div
        class="mx-auto flex items-center justify-between gap-4 px-4 py-3 sm:px-6"
        :class="anchoContenedor"
      >
        <div class="flex items-center gap-6">
          <!-- El título es el enlace al dashboard: no hay entrada "Inicio". -->
          <RouterLink
            :to="{ name: 'dashboard' }"
            class="text-primary font-heading hover:text-primary/80 text-sm font-semibold tracking-wide uppercase"
          >
            Facturación
          </RouterLink>

          <!-- Escritorio: grupos desplegables. `disable-hover-trigger` deja la
               apertura solo por clic, para que táctil y escritorio se comporten
               igual. -->
          <NavigationMenu v-model="grupoAbierto" disable-hover-trigger class="hidden md:flex">
            <NavigationMenuList class="gap-1">
              <NavigationMenuItem
                v-for="grupo in gruposNavegacion"
                :key="grupo.id"
                :value="grupo.id"
              >
                <NavigationMenuTrigger
                  class="text-muted-foreground data-[state=open]:text-foreground gap-1.5 px-3"
                  :class="grupoActivo(grupo) ? 'text-foreground font-medium' : undefined"
                >
                  <component :is="grupo.icono" class="size-4" />
                  {{ grupo.etiqueta }}
                </NavigationMenuTrigger>
                <NavigationMenuContent>
                  <ul class="w-56 p-1">
                    <li v-for="opcion in grupo.opciones" :key="opcion.name">
                      <NavigationMenuLink as-child>
                        <RouterLink
                          :to="{ name: opcion.name }"
                          class="flex-row items-center gap-2"
                          :class="opcionActiva(opcion) ? 'text-foreground font-medium' : undefined"
                        >
                          <component :is="opcion.icono" class="size-4 shrink-0" />
                          {{ opcion.etiqueta }}
                        </RouterLink>
                      </NavigationMenuLink>
                    </li>
                  </ul>
                </NavigationMenuContent>
              </NavigationMenuItem>
            </NavigationMenuList>
          </NavigationMenu>
        </div>

        <div class="flex min-w-0 items-center gap-2">
          <!-- Menú de usuario: el nombre abre un desplegable con la configuración
               del sistema y el cierre de sesión. Usa DropdownMenu y no
               NavigationMenu porque mezcla un destino con una acción (ver 003).
               Por debajo de `sm` queda solo el ícono, para dejarle lugar al
               hamburguesa en 375px. -->
          <DropdownMenu>
            <DropdownMenuTrigger as-child>
              <Button
                variant="outline"
                size="sm"
                class="min-w-0 gap-1.5"
                :class="menuUsuarioActivo ? 'text-foreground font-medium' : undefined"
              >
                <component :is="iconoUsuario" class="size-4 shrink-0" />
                <span class="hidden max-w-[12rem] truncate sm:inline" :title="nombreUsuario">
                  {{ nombreUsuario }}
                </span>
                <ChevronDownIcon class="hidden size-3 shrink-0 sm:inline" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-52">
              <DropdownMenuItem v-for="opcion in opcionesMenuUsuario" :key="opcion.name" as-child>
                <RouterLink
                  :to="{ name: opcion.name }"
                  class="flex w-full items-center gap-2"
                  :class="opcionActiva(opcion) ? 'text-foreground font-medium' : undefined"
                >
                  <component :is="opcion.icono" class="size-4 shrink-0" />
                  {{ opcion.etiqueta }}
                </RouterLink>
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem class="gap-2" @select="onLogout">
                <component :is="iconoCerrarSesion" class="size-4 shrink-0" />
                Cerrar sesión
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>

          <!-- Móvil: hamburguesa. -->
          <Button
            variant="ghost"
            size="sm"
            class="md:hidden"
            :aria-expanded="menuMovilAbierto"
            aria-controls="menu-movil"
            aria-label="Abrir menú de navegación"
            @click="menuMovilAbierto = !menuMovilAbierto"
          >
            <Bars3Icon v-if="!menuMovilAbierto" class="size-5" />
            <XMarkIcon v-else class="size-5" />
          </Button>
        </div>
      </div>

      <!-- Móvil: los mismos grupos como acordeón, sin desplegables flotantes. -->
      <nav
        v-if="menuMovilAbierto"
        id="menu-movil"
        class="border-border mx-auto border-t px-4 py-2 md:hidden"
        :class="anchoContenedor"
      >
        <div v-for="grupo in gruposNavegacion" :key="grupo.id" class="py-0.5">
          <button
            type="button"
            class="text-muted-foreground hover:text-foreground flex w-full items-center gap-2 rounded-md px-2 py-2 text-sm"
            :class="grupoActivo(grupo) ? 'text-foreground font-medium' : undefined"
            :aria-expanded="grupoMovilAbierto === grupo.id"
            @click="alternarGrupoMovil(grupo.id)"
          >
            <component :is="grupo.icono" class="size-4 shrink-0" />
            {{ grupo.etiqueta }}
            <ChevronDownIcon
              class="size-3 transition-transform"
              :class="grupoMovilAbierto === grupo.id ? 'rotate-180' : undefined"
            />
          </button>
          <ul v-if="grupoMovilAbierto === grupo.id" class="ml-4 border-l pl-2">
            <li v-for="opcion in grupo.opciones" :key="opcion.name">
              <RouterLink
                :to="{ name: opcion.name }"
                class="text-muted-foreground hover:bg-muted hover:text-foreground flex items-center gap-2 rounded-md px-2 py-2 text-sm"
                :class="opcionActiva(opcion) ? 'text-foreground font-medium' : undefined"
              >
                <component :is="opcion.icono" class="size-4 shrink-0" />
                {{ opcion.etiqueta }}
              </RouterLink>
            </li>
          </ul>
        </div>

        <!-- El menú de usuario cae al pie, tras una divisoria. Sus dos opciones
             van planas: esconderlas tras un toque más no aporta nada. -->
        <div class="border-border mt-2 border-t pt-2">
          <p class="text-muted-foreground truncate px-2 py-1 text-xs font-medium">
            {{ nombreUsuario }}
          </p>
          <RouterLink
            v-for="opcion in opcionesMenuUsuario"
            :key="opcion.name"
            :to="{ name: opcion.name }"
            class="text-muted-foreground hover:bg-muted hover:text-foreground flex items-center gap-2 rounded-md px-2 py-2 text-sm"
            :class="opcionActiva(opcion) ? 'text-foreground font-medium' : undefined"
          >
            <component :is="opcion.icono" class="size-4 shrink-0" />
            {{ opcion.etiqueta }}
          </RouterLink>
          <button
            type="button"
            class="text-muted-foreground hover:bg-muted hover:text-foreground flex w-full items-center gap-2 rounded-md px-2 py-2 text-sm"
            @click="onLogout"
          >
            <component :is="iconoCerrarSesion" class="size-4 shrink-0" />
            Cerrar sesión
          </button>
        </div>
      </nav>
    </header>

    <main class="mx-auto p-4 sm:p-6" :class="anchoContenedor">
      <slot />
    </main>
  </div>
</template>
