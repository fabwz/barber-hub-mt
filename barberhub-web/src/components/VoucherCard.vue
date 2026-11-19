<script setup lang="ts">
import { computed, ref, useId } from 'vue'
import { ChevronDown } from 'lucide-vue-next'

import StatusLabel from '@/components/StatusLabel.vue'
import { dateOf, formatDateWithYear, sessionsLabel } from '@/composables/format'
import { readMovements, type MovementType, type PurchasedVoucher } from '@/composables/useVouchers'

interface Props {
  voucher: PurchasedVoucher
}

const { voucher } = defineProps<Props>()

const MOVEMENT_LABELS: Record<MovementType, string> = {
  compra: 'Compra',
  apartado: 'Apartado',
  consumo: 'Consumo',
  reintegro: 'Reintegro',
}

const expired = computed(() => voucher.estado === 'vencido')
const movements = computed(() => readMovements(voucher.movimientos))
const services = computed(() => voucher.servicios.map((service) => service.nombre).join(', '))

/** «3 de 5 sesiones» while active; an expired voucher has no balance: «0 sesiones». */
const balance = computed(() =>
  expired.value
    ? sessionsLabel(voucher.saldo)
    : `${voucher.saldo} de ${sessionsLabel(voucher.sesiones_totales)}`,
)

const expiry = computed(() => {
  if (voucher.vence_en === null) return ''
  return `${expired.value ? 'Venció' : 'Vence'} el ${formatDateWithYear(dateOf(voucher.vence_en))}`
})

/** «+5 sesiones», «−1 sesión» or «0 sesiones»: the API gives the change with its sign. */
function change(sessions: number): string {
  if (sessions === 0) return sessionsLabel(0)
  return `${sessions > 0 ? '+' : '−'}${sessionsLabel(Math.abs(sessions))}`
}

const open = ref(false)
const listId = useId()
const titleId = useId()
</script>

<template>
  <li class="flex flex-col rounded-card border border-line bg-surface p-4">
    <div class="flex items-baseline justify-between gap-3">
      <h3 :id="titleId" class="min-w-0 font-medium">{{ voucher.nombre }}</h3>
      <StatusLabel :status="expired ? 'voucher_expired' : 'voucher_active'" />
    </div>
    <p class="mt-2 text-lg font-medium tabular-nums" :class="expired ? 'text-fg-muted' : ''">
      {{ balance }}
    </p>
    <p v-if="expiry" class="text-sm text-fg-muted">{{ expiry }}</p>
    <p v-if="services" class="mt-2 text-sm text-fg-muted">
      <span class="text-fg-subtle">Servicios cubiertos:</span> {{ services }}
    </p>

    <button
      type="button"
      class="mt-4 -mb-1 flex min-h-11 w-full cursor-pointer items-center justify-between gap-3 border-t border-line pt-1 text-left text-sm font-medium text-fg"
      :aria-expanded="open ? 'true' : 'false'"
      :aria-controls="listId"
      :aria-describedby="titleId"
      @click="open = !open"
    >
      Movimientos
      <ChevronDown
        :size="18"
        :stroke-width="1.5"
        class="flex-none transition-transform duration-150 ease-out motion-reduce:transition-none"
        :class="open ? 'rotate-180' : ''"
        aria-hidden="true"
      />
    </button>
    <ul v-show="open" :id="listId" class="border-t border-line">
      <li
        v-for="(movement, index) in movements"
        :key="index"
        class="flex min-h-14 items-center justify-between gap-3 border-b border-line py-2 last:border-b-0"
      >
        <span class="flex min-w-0 flex-col">
          <span class="font-medium">{{ MOVEMENT_LABELS[movement.type] }}</span>
          <span class="text-sm text-fg-muted">{{ formatDateWithYear(dateOf(movement.at)) }}</span>
        </span>
        <span class="flex-none text-sm tabular-nums">{{ change(movement.sessions) }}</span>
      </li>
      <li v-if="movements.length === 0" class="py-3 text-sm text-fg-muted">
        Aún no hay movimientos.
      </li>
    </ul>
  </li>
</template>
