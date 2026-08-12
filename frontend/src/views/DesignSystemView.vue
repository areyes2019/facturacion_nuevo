<script setup lang="ts">
import { Button } from '../components/ui/button'
import { Input } from '../components/ui/input'
import { Badge } from '../components/ui/badge'
import { Alert, AlertDescription, AlertTitle } from '../components/ui/alert'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '../components/ui/dialog'

const colors = [
  { name: 'primary', label: 'Primario', hex: '#4F46E5' },
  { name: 'secondary', label: 'Secundario', hex: '#7C3AED' },
  { name: 'success', label: 'Éxito', hex: '#16A34A' },
  { name: 'destructive', label: 'Error', hex: '#DC2626' },
  { name: 'warning', label: 'Advertencia', hex: '#D97706' },
  { name: 'muted', label: 'Neutro (muted)', hex: '#F1F5F9' },
  { name: 'foreground', label: 'Texto', hex: '#0F172A' },
]

const spacingScale = [
  { token: 'p-1', px: 4 },
  { token: 'p-2', px: 8 },
  { token: 'p-3', px: 12 },
  { token: 'p-4', px: 16 },
  { token: 'p-6', px: 24 },
  { token: 'p-8', px: 32 },
  { token: 'p-12', px: 48 },
  { token: 'p-16', px: 64 },
]
</script>

<template>
  <main class="mx-auto max-w-5xl space-y-12 p-6 sm:p-10">
    <header class="space-y-1">
      <p class="text-primary font-heading text-sm font-semibold tracking-wide uppercase">
        Facturación
      </p>
      <h1 class="text-3xl font-bold">Design System</h1>
      <p class="text-muted-foreground">
        Documentación viva de los tokens y componentes base (solo visible en desarrollo).
      </p>
    </header>

    <!-- Colores -->
    <section class="space-y-4">
      <h2 class="text-xl font-semibold">Colores</h2>
      <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
        <div v-for="color in colors" :key="color.name" class="space-y-2">
          <div
            class="border-border h-16 rounded-lg border"
            :style="{ backgroundColor: color.hex }"
          />
          <p class="text-sm font-medium">{{ color.label }}</p>
          <p class="text-muted-foreground font-mono text-xs">{{ color.hex }}</p>
        </div>
      </div>
    </section>

    <!-- Tipografía -->
    <section class="space-y-4">
      <h2 class="text-xl font-semibold">Tipografía</h2>
      <Card>
        <CardContent class="space-y-4 pt-6">
          <div>
            <p class="text-muted-foreground text-xs">font-heading — Roboto</p>
            <p class="font-heading text-3xl font-bold">Título con Roboto</p>
          </div>
          <div>
            <p class="text-muted-foreground text-xs">font-sans (default) — Open Sans</p>
            <p class="font-sans text-base">
              Texto de cuerpo con Open Sans. Lorem ipsum dolor sit amet, consectetur adipiscing.
            </p>
          </div>
        </CardContent>
      </Card>
    </section>

    <!-- Spacing -->
    <section class="space-y-4">
      <h2 class="text-xl font-semibold">Spacing</h2>
      <div class="space-y-2">
        <div v-for="s in spacingScale" :key="s.token" class="flex items-center gap-3">
          <span class="text-muted-foreground w-12 font-mono text-xs">{{ s.token }}</span>
          <div class="bg-primary h-4" :style="{ width: `${s.px}px` }" />
        </div>
      </div>
    </section>

    <!-- Button -->
    <section class="space-y-4">
      <h2 class="text-xl font-semibold">Button</h2>
      <div class="flex flex-wrap gap-3">
        <Button variant="default">Default</Button>
        <Button variant="secondary">Secondary</Button>
        <Button variant="destructive">Destructive</Button>
        <Button variant="outline">Outline</Button>
        <Button variant="ghost">Ghost</Button>
        <Button variant="link">Link</Button>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <Button size="sm">Small</Button>
        <Button size="default">Default</Button>
        <Button size="lg">Large</Button>
        <Button disabled>Disabled</Button>
      </div>
    </section>

    <!-- Input -->
    <section class="space-y-4">
      <h2 class="text-xl font-semibold">Input</h2>
      <div class="grid max-w-sm gap-3">
        <Input placeholder="Placeholder" />
        <Input placeholder="Deshabilitado" disabled />
      </div>
    </section>

    <!-- Card -->
    <section class="space-y-4">
      <h2 class="text-xl font-semibold">Card</h2>
      <Card class="max-w-sm">
        <CardHeader>
          <CardTitle>Título de la card</CardTitle>
          <CardDescription>Descripción breve del contenido.</CardDescription>
        </CardHeader>
        <CardContent>
          <p class="text-sm">Contenido de ejemplo dentro de la card.</p>
        </CardContent>
      </Card>
    </section>

    <!-- Alert -->
    <section class="space-y-4">
      <h2 class="text-xl font-semibold">Alert</h2>
      <div class="grid gap-3">
        <Alert variant="default">
          <AlertTitle>Info</AlertTitle>
          <AlertDescription>Mensaje informativo general.</AlertDescription>
        </Alert>
        <Alert variant="success">
          <AlertTitle>Éxito</AlertTitle>
          <AlertDescription>La operación se completó correctamente.</AlertDescription>
        </Alert>
        <Alert variant="warning">
          <AlertTitle>Advertencia</AlertTitle>
          <AlertDescription>Revisa este dato antes de continuar.</AlertDescription>
        </Alert>
        <Alert variant="destructive">
          <AlertTitle>Error</AlertTitle>
          <AlertDescription>Ocurrió un error al procesar la solicitud.</AlertDescription>
        </Alert>
      </div>
    </section>

    <!-- Badge -->
    <section class="space-y-4">
      <h2 class="text-xl font-semibold">Badge</h2>
      <div class="flex flex-wrap gap-3">
        <Badge variant="default">Default</Badge>
        <Badge variant="secondary">Secondary</Badge>
        <Badge variant="success">Success</Badge>
        <Badge variant="warning">Warning</Badge>
        <Badge variant="destructive">Destructive</Badge>
        <Badge variant="outline">Outline</Badge>
      </div>
    </section>

    <!-- Modal -->
    <section class="space-y-4">
      <h2 class="text-xl font-semibold">Modal</h2>
      <Dialog>
        <DialogTrigger as-child>
          <Button variant="outline">Abrir modal</Button>
        </DialogTrigger>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Título del modal</DialogTitle>
            <DialogDescription>Descripción de ejemplo para el modal base.</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline">Cancelar</Button>
            <Button>Confirmar</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </section>
  </main>
</template>
