<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Button } from '../components/ui/button'
import { Alert, AlertDescription } from '../components/ui/alert'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const remember = ref(false)

async function onSubmit() {
  try {
    await auth.login({ email: email.value, password: password.value, remember: remember.value })

    // Solo se acepta una ruta interna: un `redirect` con dominio ajeno convertiría el login en un
    // trampolín hacia otro sitio.
    const destino = route.query.redirect
    const esInterna =
      typeof destino === 'string' && destino.startsWith('/') && !destino.startsWith('//')

    await router.push(esInterna ? destino : { name: 'dashboard' })
  } catch {
    // El mensaje de error ya queda expuesto en auth.error.
  }
}
</script>

<template>
  <main class="bg-muted flex min-h-screen items-center justify-center p-4">
    <Card class="w-full max-w-sm">
      <CardHeader class="text-center">
        <p class="text-primary font-heading text-sm font-semibold tracking-wide uppercase">
          Facturación
        </p>
        <CardTitle class="text-2xl">Iniciar sesión</CardTitle>
        <CardDescription>Ingresa tus credenciales para continuar</CardDescription>
      </CardHeader>
      <form @submit.prevent="onSubmit">
        <CardContent class="flex flex-col gap-4">
          <div class="flex flex-col gap-1.5">
            <label for="email" class="text-sm font-medium">Correo</label>
            <Input id="email" v-model="email" type="email" required autocomplete="email" />
          </div>
          <div class="flex flex-col gap-1.5">
            <label for="password" class="text-sm font-medium">Contraseña</label>
            <Input
              id="password"
              v-model="password"
              type="password"
              required
              autocomplete="current-password"
            />
          </div>
          <label class="text-muted-foreground flex items-center gap-2 text-sm">
            <input v-model="remember" type="checkbox" class="border-input size-4 rounded" />
            Recordarme
          </label>
          <Alert v-if="auth.error" variant="destructive">
            <AlertDescription>{{ auth.error }}</AlertDescription>
          </Alert>
        </CardContent>
        <CardFooter class="flex flex-col gap-3">
          <Button type="submit" class="w-full" :disabled="auth.loading">Entrar</Button>
          <RouterLink
            :to="{ name: 'forgot-password' }"
            class="text-primary text-sm hover:underline"
          >
            Olvidé mi contraseña
          </RouterLink>
        </CardFooter>
      </form>
    </Card>
  </main>
</template>
