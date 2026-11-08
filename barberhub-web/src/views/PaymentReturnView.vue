<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import BrandLogo from '@/components/BrandLogo.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import { readStoredPayment, writeStoredPayment } from '@/composables/usePayment'

const route = useRoute()
const router = useRouter()

/**
 * The provider (PayPal) sends the person back here. Nothing in the address is trusted: what was
 * saved before leaving says which payment this was, and the payment page asks the API for its status.
 */
onMounted(() => {
  const stored = readStoredPayment()
  if (stored === null) {
    void router.replace('/')
    return
  }
  const cancelled = route.query.cancelado === '1'
  // A cancelled payment is not resumed: the person chooses again.
  if (cancelled) writeStoredPayment(null)
  void router.replace({
    name: 'payment',
    params: { enlace: stored.enlace, reserva: stored.reserva },
    query: cancelled ? { retorno: '1', cancelado: '1' } : { retorno: '1' },
  })
})
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <header class="flex h-16 flex-none items-center px-5 md:px-8">
      <BrandLogo />
    </header>
    <main class="px-5 pt-4 pb-10 md:px-8 md:pt-12" aria-busy="true">
      <div class="mx-auto flex w-full max-w-110 flex-col gap-4" role="status">
        <h1 class="text-2xl">Verificando su pago…</h1>
        <SkeletonBlock shape="block" label="Verificando su pago" />
      </div>
    </main>
  </div>
</template>
