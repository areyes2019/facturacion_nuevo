<script setup lang="ts">
import { ref } from 'vue'
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

const auth = useAuthStore()

const email = ref('')
const sent = ref(false)

async function onSubmit() {
  try {
    await auth.forgotPassword(email.value)
    sent.value = true
  } catch {
    // El mensaje de error ya queda expuesto en auth.error.
  }
}
</script>

<template>
  <main class="bg-muted flex min-h-screen items-center justify-center p-4">
    <Card class="w-full max-w-sm">
      <CardHeader class="text-center">
        <CardTitle class="text-2xl">Olvidé mi contraseña</CardTitle>
        <CardDescription>Te enviaremos un link para restablecerla</CardDescription>
      </CardHeader>

      <template v-if="!sent">
        <form @submit.prevent="onSubmit">
          <CardContent class="flex flex-col gap-4">
            <div class="flex flex-col gap-1.5">
              <label for="email" class="text-sm font-medium">Correo</label>
              <Input id="email" v-model="email" type="email" required autocomplete="email" />
            </div>
            <Alert v-if="auth.error" variant="destructive">
              <AlertDescription>{{ auth.error }}</AlertDescription>
            </Alert>
          </CardContent>
          <CardFooter class="flex flex-col gap-3">
            <Button type="submit" class="w-full" :disabled="auth.loading">
              Enviar link de recuperación
            </Button>
            <RouterLink :to="{ name: 'login' }" class="text-primary text-sm hover:underline">
              Volver a iniciar sesión
            </RouterLink>
          </CardFooter>
        </form>
      </template>
      <template v-else>
        <CardContent>
          <Alert variant="success">
            <AlertDescription
              >Si el correo existe, te enviamos un link de recuperación.</AlertDescription
            >
          </Alert>
        </CardContent>
        <CardFooter>
          <RouterLink :to="{ name: 'login' }" class="text-primary text-sm hover:underline">
            Volver a iniciar sesión
          </RouterLink>
        </CardFooter>
      </template>
    </Card>
  </main>
</template>
