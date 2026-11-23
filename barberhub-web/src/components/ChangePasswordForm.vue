<script setup lang="ts">
import { nextTick, ref, useTemplateRef } from 'vue'

import { isApiError } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import PasswordChecklist from '@/components/PasswordChecklist.vue'
import PasswordField from '@/components/PasswordField.vue'
import { meetsPasswordPolicy } from '@/composables/passwordPolicy'
import { useFormErrors } from '@/composables/useFormErrors'
import { useAccountStore } from '@/stores/account'

// The form of RF-40, shared by «Mi cuenta · Seguridad» and the mandatory change of a temporary
// password (UP-04): the current password, the new one with the SE-07 list and its confirmation.
interface Props {
  /** Label of the first field: «Contraseña actual», or «Contraseña temporal» in the mandatory change. */
  currentLabel?: string
  /** What the person has to type in the first field, in the message of an empty one. */
  currentRequired?: string
  submitLabel?: string
}

const {
  currentLabel = 'Contraseña actual',
  currentRequired = 'Escriba su contraseña actual.',
  submitLabel = 'Cambiar contraseña',
} = defineProps<Props>()

const emit = defineEmits<{
  /** The API changed the password; the fields are already empty. */
  changed: []
}>()

type Field = 'current' | 'next' | 'confirm'
const FIELD_ORDER: readonly Field[] = ['current', 'next', 'confirm']

const WRONG_CURRENT = 'La contraseña actual es incorrecta.'
const MISMATCH = 'Las contraseñas no coinciden.'

const account = useAccountStore()

const current = ref('')
const next = ref('')
const confirmation = ref('')
const saving = ref(false)
const attempted = ref(false)
const failureNotice = ref(false)
const announcement = ref('')

const errors = useFormErrors<Field>({ contrasena_actual: 'current', contrasena_nueva: 'next' })

const fields = {
  current: useTemplateRef<InstanceType<typeof PasswordField>>('currentField'),
  next: useTemplateRef<InstanceType<typeof PasswordField>>('nextField'),
  confirm: useTemplateRef<InstanceType<typeof PasswordField>>('confirmField'),
}

function validateLocally(): void {
  if (current.value === '') errors.setField('current', currentRequired)
  if (!meetsPasswordPolicy(next.value)) {
    errors.setField(
      'next',
      next.value === ''
        ? 'Escriba la contraseña nueva.'
        : 'La contraseña nueva no cumple los requisitos.',
    )
  }
  if (confirmation.value !== next.value) errors.setField('confirm', MISMATCH)
}

async function focusFirstError(): Promise<void> {
  const first = errors.firstField(FIELD_ORDER)
  announcement.value = first ? 'Revise los campos marcados.' : ''
  await nextTick()
  if (first) fields[first].value?.focus()
}

function reset(): void {
  // No password stays in memory once it has been used.
  current.value = next.value = confirmation.value = ''
  attempted.value = false
  errors.clear()
}

async function submit(): Promise<void> {
  if (saving.value) return
  errors.clear()
  failureNotice.value = false
  attempted.value = true
  validateLocally()
  if (errors.firstField(FIELD_ORDER)) {
    await focusFirstError()
    return
  }

  saving.value = true
  try {
    await account.changePassword(current.value, next.value)
    reset()
    emit('changed')
  } catch (error) {
    if (!isApiError(error)) throw error
    errors.setFromApiError(error)
    // The API never says more than this about the current password.
    if (errors.fieldErrors.value.current !== undefined) errors.setField('current', WRONG_CURRENT)
    failureNotice.value = errors.generalError.value !== ''
    // The fields are disabled while sending; enable them before moving the focus.
    saving.value = false
    await focusFirstError()
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <form novalidate :aria-busy="saving ? 'true' : undefined" @submit.prevent="submit">
    <p class="sr-only" aria-live="polite">{{ announcement }}</p>
    <NoticeBanner v-if="failureNotice" variant="error" class="mb-6">
      <p class="font-medium">No pudimos cambiar la contraseña.</p>
      <p class="mt-1 text-fg-muted">{{ errors.generalError.value }} Lo que escribió se mantiene.</p>
    </NoticeBanner>

    <div class="flex flex-col gap-5" :class="saving ? 'opacity-60' : ''">
      <PasswordField
        ref="currentField"
        v-model="current"
        :label="currentLabel"
        name="current-password"
        autocomplete="current-password"
        :disabled="saving"
        :error="errors.fieldErrors.value.current"
        @input="errors.clearField('current')"
      />
      <PasswordField
        ref="nextField"
        v-model="next"
        label="Contraseña nueva"
        name="new-password"
        autocomplete="new-password"
        aria-describedby="password-checklist"
        :disabled="saving"
        :error="errors.fieldErrors.value.next"
        @input="errors.clearField('next')"
      />
      <PasswordChecklist id="password-checklist" :password="next" :failed="attempted" />
      <PasswordField
        ref="confirmField"
        v-model="confirmation"
        label="Confirmar contraseña nueva"
        name="confirm-password"
        autocomplete="new-password"
        :disabled="saving"
        :error="errors.fieldErrors.value.confirm"
        @input="errors.clearField('confirm')"
      />
    </div>

    <BaseButton type="submit" size="large" class="mt-8" :loading="saving">
      {{ saving ? 'Guardando' : submitLabel }}
    </BaseButton>
  </form>
</template>
