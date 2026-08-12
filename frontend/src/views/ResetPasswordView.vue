<script setup lang="ts">
import { ref } from 'vue'
import { useRoute } from 'vue-router'
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

const route = useRoute()
const auth = useAuthStore()

const token = ref(String(route.query.token ?? ''))
const email = ref(String(route.query.email ?? ''))
const password = ref('')
const passwordConfirmation = ref('')
const done = ref(false)

async function onSubmit() {
  try {
    await auth.resetPassword({
      token: token.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    done.value = true
  } catch {
    // El mensaje de error ya queda expuesto en auth.error.
  }
}
</script>

<template>
  <main class="bg-muted flex min-h-screen items-center justify-center p-4">
    <Card class="w-full max-w-sm">
      <CardHeader class="text-center">
        <CardTitle class="text-2xl">Restablecer contraseña</CardTitle>
        <CardDescription>Elige una nueva contraseña para tu cuenta</CardDescription>
      </CardHeader>

      <template v-if="!done">
        <form @submit.prevent="onSubmit">
          <CardContent class="flex flex-col gap-4">
            <div class="flex flex-col gap-1.5">
              <label for="email" class="text-sm font-medium">Correo</label>
              <Input id="email" v-model="email" type="email" required autocomplete="email" />
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="password" class="text-sm font-medium">Nueva contraseña</label>
              <Input
                id="password"
                v-model="password"
                type="password"
                required
                autocomplete="new-password"
                minlength="8"
              />
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="password_confirmation" class="text-sm font-medium">
                Confirmar contraseña
              </label>
              <Input
                id="password_confirmation"
                v-model="passwordConfirmation"
                type="password"
                required
                autocomplete="new-password"
                minlength="8"
              />
            </div>
            <Alert v-if="auth.error" variant="destructive">
              <AlertDescription>{{ auth.error }}</AlertDescription>
            </Alert>
          </CardContent>
          <CardFooter>
            <Button type="submit" class="w-full" :disabled="auth.loading">
              Restablecer contraseña
            </Button>
          </CardFooter>
        </form>
      </template>
      <template v-else>
        <CardContent>
          <Alert variant="success">
            <AlertDescription>Tu contraseña fue actualizada.</AlertDescription>
          </Alert>
        </CardContent>
        <CardFooter>
          <RouterLink :to="{ name: 'login' }" class="text-primary text-sm hover:underline">
            Iniciar sesión
          </RouterLink>
        </CardFooter>
      </template>
    </Card>
  </main>
</template>
