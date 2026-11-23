<script setup lang="ts">
import { ref } from 'vue'
import { Check, Copy } from 'lucide-vue-next'

import BaseButton from '@/components/BaseButton.vue'
import type { CreatedAccount } from '@/composables/useCollaboratorForm'

interface Props {
  account: CreatedAccount
  /** How many services the shop has, to say «5 de 7 servicios». */
  servicesTotal: number
}

const { account, servicesTotal } = defineProps<Props>()

const emit = defineEmits<{
  /** «Listo»: the owner wants to leave; the screen asks if the password was copied. */
  done: []
}>()

// The temporary password of a new collaborator (UP-04, SE-07): shown here once, never saved and never logged.
// The API does not return it, so once this screen is left there is no way to read it again.
const copied = ref(false)
const copyFailed = ref(false)

async function copy(): Promise<void> {
  copied.value = false
  copyFailed.value = false
  try {
    await navigator.clipboard.writeText(account.password)
    copied.value = true
  } catch {
    // No clipboard (blocked or an insecure page): the password is on the screen to copy by hand.
    copyFailed.value = true
  }
}
</script>

<template>
  <section aria-labelledby="created-title" class="flex flex-col gap-6">
    <div>
      <h2 id="created-title" class="text-lg font-medium">Colaborador creado</h2>
      <p class="mt-1 text-sm text-fg-muted">
        La cuenta de {{ account.name }} ya está activa. Entregue esta contraseña temporal por un
        medio seguro.
      </p>
    </div>

    <div class="rounded-card border border-line bg-surface p-5">
      <p class="text-sm font-medium text-fg-muted">Contraseña temporal</p>
      <p
        class="mt-2 text-2xl font-normal break-all text-fg select-all tabular-nums"
        data-testid="temporary-password"
      >
        <span class="font-mono">{{ account.password }}</span>
      </p>
      <div class="mt-4 flex flex-wrap items-center gap-3">
        <BaseButton variant="secondary" @click="copy">
          <template #icon>
            <Check v-if="copied" :size="16" :stroke-width="1.5" aria-hidden="true" />
            <Copy v-else :size="16" :stroke-width="1.5" aria-hidden="true" />
          </template>
          {{ copied ? 'Copiada' : 'Copiar contraseña' }}
        </BaseButton>
        <p v-if="copied" role="status" class="text-sm text-fg">
          Contraseña copiada al portapapeles.
        </p>
        <p v-if="copyFailed" role="alert" class="text-sm text-danger">
          No pudimos copiarla. Selecciónela y cópiela a mano.
        </p>
      </div>
      <p class="mt-4 text-sm font-medium text-fg">Solo la verá ahora</p>
      <p class="text-sm text-fg-muted">Por seguridad no se vuelve a mostrar.</p>
      <p class="mt-3 text-sm text-fg-muted">
        Deberá cambiarla en su primer ingreso. Ingresará con {{ account.email }}.
      </p>
    </div>

    <div>
      <h3 class="text-base font-medium">Resumen de la cuenta</h3>
      <dl class="mt-2 divide-y divide-line border-y border-line text-sm">
        <div class="flex justify-between gap-4 py-2">
          <dt class="text-fg-muted">Nombre</dt>
          <dd class="text-right">{{ account.name }}</dd>
        </div>
        <div class="flex justify-between gap-4 py-2">
          <dt class="text-fg-muted">Correo</dt>
          <dd class="text-right">{{ account.email }}</dd>
        </div>
        <div class="flex justify-between gap-4 py-2">
          <dt class="text-fg-muted">Servicios habilitados</dt>
          <dd class="text-right tabular-nums">{{ account.services }} de {{ servicesTotal }}</dd>
        </div>
        <div class="flex justify-between gap-4 py-2">
          <dt class="text-fg-muted">Horario</dt>
          <dd class="text-right tabular-nums">{{ account.schedule }}</dd>
        </div>
      </dl>
    </div>

    <div>
      <BaseButton size="large" @click="emit('done')">Listo</BaseButton>
    </div>
  </section>
</template>
