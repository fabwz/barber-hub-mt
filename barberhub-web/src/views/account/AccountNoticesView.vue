<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

import { isApiError } from '@/api'
import ErrorState from '@/components/ErrorState.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import SwitchField from '@/components/SwitchField.vue'
import { useAccountStore } from '@/stores/account'

// «Mi cuenta · Avisos» (RF-47, NO-10): the customer turns the reminders by email on or off. The
// preference lives in the account and applies to every reservation in every shop. The switch is
// not optimistic: it keeps showing the saved value, dimmed, until the API answers.
const account = useAccountStore()

const saving = ref(false)
const saveFailed = ref(false)
/** The value that was being saved when the save failed, so «Reintentar» tries the same one. */
const attempted = ref<boolean | null>(null)

const loading = computed(
  () => account.noticesStatus === 'idle' || account.noticesStatus === 'loading',
)
const enabled = computed(() => account.remindersEnabled === true)

async function load(): Promise<void> {
  await account.loadNotices()
}

async function save(value: boolean): Promise<void> {
  if (saving.value) return
  saving.value = true
  saveFailed.value = false
  attempted.value = value
  try {
    await account.saveReminders(value)
    attempted.value = null
  } catch (error) {
    // A 401 closes the session in the client; any other failure leaves the preference as it was.
    if (!isApiError(error)) throw error
    saveFailed.value = true
  } finally {
    saving.value = false
  }
}

function retry(): void {
  if (attempted.value !== null) void save(attempted.value)
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section
    :aria-busy="loading || saving ? 'true' : undefined"
    aria-labelledby="account-notices-title"
  >
    <h2 id="account-notices-title" class="text-xl">Avisos</h2>
    <p class="mt-2 text-fg-muted">Elija qué correos recordatorios quiere recibir.</p>

    <div v-if="loading" class="mt-6 max-w-2xl">
      <SkeletonBlock shape="row" label="Cargando sus avisos" />
    </div>

    <ErrorState
      v-else-if="account.noticesStatus === 'error'"
      class="mt-6 max-w-2xl"
      message="No pudimos cargar sus avisos"
      description="Revise su conexión e inténtelo de nuevo."
      @retry="load"
    />

    <div v-else class="mt-6 flex max-w-2xl flex-col gap-4">
      <div class="rounded-card border border-line p-4">
        <SwitchField
          :model-value="enabled"
          :busy="saving"
          label="Recordatorios por correo"
          description="Un correo 24 horas antes y otro 2 horas antes de cada cita. Aplica a sus reservas en todas las barberías."
          @update:model-value="save"
        />
        <p v-if="saving" role="status" class="mt-3 text-sm text-fg-muted">Guardando…</p>
      </div>

      <ErrorState
        v-if="saveFailed"
        message="No pudimos guardar el cambio"
        description="Su preferencia sigue como estaba. Inténtelo de nuevo."
        @retry="retry"
      />

      <p class="text-sm text-fg-muted">
        Siempre le enviaremos por correo la confirmación de sus reservas, las ofertas de espacio,
        las cancelaciones o reprogramaciones de la barbería, los reembolsos y el restablecimiento de
        contraseña. Estos avisos no se pueden desactivar.
      </p>
    </div>
  </section>
</template>
