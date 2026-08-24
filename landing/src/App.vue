<script setup lang="ts">
import AppHeader from '@/components/AppHeader.vue'
import HeroSection from '@/components/HeroSection.vue'
import ProblemaSection from '@/components/ProblemaSection.vue'
import PropuestaValorSection from '@/components/PropuestaValorSection.vue'
import ProductosSection from '@/components/ProductosSection.vue'
import ParaQuienSection from '@/components/ParaQuienSection.vue'
import DistribuidoresSection from '@/components/DistribuidoresSection.vue'
import DiferenciadorSection from '@/components/DiferenciadorSection.vue'
import PorQueSection from '@/components/PorQueSection.vue'
import CtaPrincipalSection from '@/components/CtaPrincipalSection.vue'
import ContactoSection from '@/components/ContactoSection.vue'
import AppFooter from '@/components/AppFooter.vue'
import WhatsappFlotante from '@/components/WhatsappFlotante.vue'
</script>

<template>
  <AppHeader />
  <main>
    <HeroSection />
    <ProblemaSection />
    <PropuestaValorSection />
    <ProductosSection />
    <ParaQuienSection />
    <DistribuidoresSection />
    <DiferenciadorSection />
    <PorQueSection />
    <CtaPrincipalSection />
    <ContactoSection />
  </main>
  <AppFooter />
  <WhatsappFlotante />
</template>
