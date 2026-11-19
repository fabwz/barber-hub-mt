<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'

import { colones, sessionsLabel } from '@/composables/format'
import type { VoucherOnSale } from '@/composables/useVouchers'

interface Props {
  voucher: VoucherOnSale
  /** Where «Comprar bono» leads. */
  to: RouteLocationRaw
}

const { voucher } = defineProps<Props>()

const services = computed(() => voucher.servicios.map((service) => service.nombre).join(', '))
</script>

<template>
  <li class="rounded-card border border-line bg-surface p-4">
    <h3 class="font-medium">{{ voucher.nombre }}</h3>
    <dl class="mt-2 text-sm">
      <div class="flex justify-between gap-6 border-b border-line-strong py-2">
        <dt class="text-fg-muted">Sesiones</dt>
        <dd class="text-right tabular-nums">{{ sessionsLabel(voucher.sesiones) }}</dd>
      </div>
      <div class="flex justify-between gap-6 border-b border-line-strong py-2">
        <dt class="text-fg-muted">Vigencia</dt>
        <dd class="text-right tabular-nums">{{ voucher.vigencia_dias }} días</dd>
      </div>
      <div class="flex justify-between gap-6 py-2">
        <dt class="text-fg-muted">Servicios cubiertos</dt>
        <dd class="text-right">{{ services }}</dd>
      </div>
    </dl>
    <div class="mt-2 flex items-center justify-between gap-4">
      <p class="tabular-nums">
        <span class="text-lg">{{ colones(voucher.precio) }}</span>
        <span class="ml-2 text-sm text-fg-subtle">IVA incluido</span>
      </p>
      <RouterLink
        :to="to"
        :aria-label="`Comprar bono: ${voucher.nombre}`"
        class="inline-flex min-h-11 items-center justify-center rounded-pill border border-line-strong px-5 text-sm font-normal text-fg no-underline transition-colors duration-150 ease-out hover:border-line-hover"
      >
        Comprar bono
      </RouterLink>
    </div>
  </li>
</template>
