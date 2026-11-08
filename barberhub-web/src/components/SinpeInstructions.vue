<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import { Check, Copy } from 'lucide-vue-next'

import BaseButton from '@/components/BaseButton.vue'
import { colones } from '@/composables/format'

interface Props {
  /** `numero_destino` of the instructions the API returns. */
  destination: string
  /** Exact amount of the transfer, in colones. */
  amount: number
}

const { destination, amount } = defineProps<Props>()

type Item = 'destination' | 'amount'

const copied = ref<Item | null>(null)
const failed = ref(false)
let timer: ReturnType<typeof setTimeout> | null = null

async function copy(item: Item, value: string): Promise<void> {
  if (timer !== null) clearTimeout(timer)
  try {
    await globalThis.navigator.clipboard.writeText(value)
    copied.value = item
    failed.value = false
    timer = setTimeout(() => (copied.value = null), 2500)
  } catch {
    copied.value = null
    failed.value = true
  }
}

onBeforeUnmount(() => {
  if (timer !== null) clearTimeout(timer)
})
</script>

<template>
  <section aria-labelledby="sinpe-title" class="flex flex-col gap-4">
    <div>
      <h2 id="sinpe-title" class="text-lg font-medium">Complete el SINPE Móvil</h2>
      <p class="mt-1 text-sm text-fg-muted">
        Haga la transferencia desde la app de su banco con estos datos. El monto debe ser exacto.
      </p>
    </div>

    <dl class="flex flex-col rounded-card border border-line bg-surface px-4">
      <div class="flex items-center justify-between gap-4 border-b border-line py-3">
        <div class="min-w-0">
          <dt class="text-sm text-fg-muted">Número de destino</dt>
          <dd class="font-medium tabular-nums">{{ destination }}</dd>
        </div>
        <BaseButton
          variant="secondary"
          aria-label="Copiar número de destino"
          @click="copy('destination', destination)"
        >
          <template #icon>
            <Check
              v-if="copied === 'destination'"
              :size="16"
              :stroke-width="1.5"
              aria-hidden="true"
            />
            <Copy v-else :size="16" :stroke-width="1.5" aria-hidden="true" />
          </template>
          {{ copied === 'destination' ? 'Copiado' : 'Copiar' }}
        </BaseButton>
      </div>
      <div class="flex items-center justify-between gap-4 py-3">
        <div class="min-w-0">
          <dt class="text-sm text-fg-muted">Monto exacto</dt>
          <dd class="font-medium tabular-nums">{{ colones(amount) }}</dd>
        </div>
        <BaseButton
          variant="secondary"
          aria-label="Copiar monto"
          @click="copy('amount', String(amount))"
        >
          <template #icon>
            <Check v-if="copied === 'amount'" :size="16" :stroke-width="1.5" aria-hidden="true" />
            <Copy v-else :size="16" :stroke-width="1.5" aria-hidden="true" />
          </template>
          {{ copied === 'amount' ? 'Copiado' : 'Copiar' }}
        </BaseButton>
      </div>
    </dl>

    <p class="sr-only" role="status" aria-live="polite">
      <template v-if="copied === 'destination'">Número de destino copiado.</template>
      <template v-else-if="copied === 'amount'">Monto copiado.</template>
      <template v-else-if="failed">No se pudo copiar.</template>
    </p>
    <p v-if="failed" class="text-sm text-danger">
      No se pudo copiar. Seleccione el dato y cópielo a mano.
    </p>
  </section>
</template>
