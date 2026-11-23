<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useTemplateRef } from 'vue'

import { isApiError } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import TextField from '@/components/TextField.vue'
import {
  MAX_EMAIL,
  MAX_NAME,
  MAX_PHONE_TEXT,
  PHONE_DIGITS,
  cleanName,
  cleanPhone,
  emailProblem,
  phoneDigits,
  restrict,
} from '@/composables/fieldInput'
import { useFormErrors } from '@/composables/useFormErrors'
import { useAccountStore } from '@/stores/account'
import { useSessionStore } from '@/stores/session'

// «Mi cuenta · Datos» (RF-45, CL-03): the person sees and corrects their name, phone and email.
type Field = 'name' | 'phone' | 'email'
const FIELD_ORDER: readonly Field[] = ['name', 'phone', 'email']

const DUPLICATE_EMAIL = 'Este correo ya está registrado. Use otro correo.'

const account = useAccountStore()
const session = useSessionStore()

const name = ref('')
const phone = ref('')
const email = ref('')
const saving = ref(false)
const saved = ref(false)
const failureNotice = ref(false)
const announcement = ref('')

const errors = useFormErrors<Field>({ nombre: 'name', telefono: 'phone', correo: 'email' })

const fields = {
  name: useTemplateRef<InstanceType<typeof TextField>>('nameField'),
  phone: useTemplateRef<InstanceType<typeof TextField>>('phoneField'),
  email: useTemplateRef<InstanceType<typeof TextField>>('emailField'),
}

const loading = computed(() => account.status === 'idle' || account.status === 'loading')

/** Fills the form with what the API holds. */
function fill(): void {
  const profile = account.profile
  if (profile === null) return
  name.value = profile.name
  phone.value = cleanPhone(profile.phone)
  email.value = profile.email
}

async function load(): Promise<void> {
  await account.load()
  fill()
}

function touched(field?: Field): void {
  saved.value = false
  failureNotice.value = false
  if (field !== undefined) errors.clearField(field)
}

function onNameInput(event: Event): void {
  restrict(event, cleanName, name)
  touched('name')
}

function onPhoneInput(event: Event): void {
  restrict(event, cleanPhone, phone)
  touched('phone')
}

function validateLocally(): void {
  if (name.value.trim() === '') errors.setField('name', 'Escriba su nombre completo.')
  if (phone.value.trim() === '') {
    errors.setField('phone', 'Escriba su teléfono.')
  } else if (phoneDigits(phone.value).length < PHONE_DIGITS) {
    errors.setField('phone', 'El teléfono debe tener 8 dígitos. Ejemplo: 1234-5678.')
  }
  const emailMessage = emailProblem(email.value)
  if (emailMessage !== '') errors.setField('email', emailMessage)
}

async function focusFirstError(): Promise<void> {
  const first = errors.firstField(FIELD_ORDER)
  announcement.value = first ? 'Revise los campos marcados.' : ''
  await nextTick()
  if (first) fields[first].value?.focus()
}

async function submit(): Promise<void> {
  if (saving.value) return
  errors.clear()
  saved.value = false
  failureNotice.value = false
  validateLocally()
  if (errors.firstField(FIELD_ORDER)) {
    await focusFirstError()
    return
  }

  saving.value = true
  try {
    await account.save({
      name: name.value.trim(),
      phone: phoneDigits(phone.value),
      email: email.value.trim(),
    })
    fill()
    // The email is the login: the account menu shows the new one.
    if (account.profile !== null) session.setEmail(account.profile.email)
    saved.value = true
  } catch (error) {
    if (!isApiError(error)) throw error
    errors.setFromApiError(error)
    // A 422 on the email can only be another account that already uses it: the client checked its shape.
    if (errors.fieldErrors.value.email !== undefined) errors.setField('email', DUPLICATE_EMAIL)
    failureNotice.value = errors.generalError.value !== ''
    // The fields are disabled while sending; enable them before moving the focus.
    saving.value = false
    await focusFirstError()
  } finally {
    saving.value = false
  }
}

// The router may have read the account already; if it could not, it is read again here.
onMounted(async () => {
  if (account.status === 'ready') fill()
  else await load()
})
</script>

<template>
  <section :aria-busy="saving || loading ? 'true' : undefined" aria-labelledby="account-data-title">
    <h2 id="account-data-title" class="text-xl">Datos personales</h2>
    <p class="mt-2 text-fg-muted">
      Su nombre, su teléfono y el correo con el que ingresa a BarberHub.
    </p>

    <div v-if="loading" class="mt-6 max-w-2xl">
      <SkeletonBlock shape="row" :count="3" label="Cargando sus datos" />
    </div>

    <ErrorState
      v-else-if="account.status === 'error'"
      class="mt-6 max-w-2xl"
      message="No pudimos cargar sus datos"
      description="Revise su conexión e inténtelo de nuevo."
      @retry="load"
    />

    <form v-else novalidate class="mt-6 max-w-2xl" @submit.prevent="submit">
      <p class="sr-only" aria-live="polite">{{ announcement }}</p>
      <NoticeBanner v-if="failureNotice" variant="error" class="mb-6">
        <p class="font-medium">No pudimos guardar sus datos.</p>
        <p class="mt-1 text-fg-muted">
          {{ errors.generalError.value }} Lo que escribió se mantiene.
        </p>
      </NoticeBanner>

      <div class="flex flex-col gap-6" :class="saving ? 'opacity-60' : ''">
        <TextField
          ref="nameField"
          v-model="name"
          label="Nombre completo"
          name="name"
          autocomplete="name"
          :maxlength="MAX_NAME"
          :disabled="saving"
          :error="errors.fieldErrors.value.name"
          @input="onNameInput"
        />
        <TextField
          ref="phoneField"
          v-model="phone"
          label="Teléfono"
          type="tel"
          name="phone"
          autocomplete="tel"
          inputmode="numeric"
          placeholder="1234-5678"
          :maxlength="MAX_PHONE_TEXT"
          :disabled="saving"
          :error="errors.fieldErrors.value.phone"
          @input="onPhoneInput"
        />
        <TextField
          ref="emailField"
          v-model="email"
          label="Correo electrónico"
          type="email"
          name="email"
          autocomplete="email"
          inputmode="email"
          :maxlength="MAX_EMAIL"
          :disabled="saving"
          :error="errors.fieldErrors.value.email"
          @input="touched('email')"
        />
      </div>

      <div class="mt-8 flex flex-col gap-4">
        <div>
          <BaseButton type="submit" size="large" :loading="saving">
            {{ saving ? 'Guardando' : 'Guardar cambios' }}
          </BaseButton>
        </div>
        <NoticeBanner v-if="saved" variant="success">Sus datos se guardaron.</NoticeBanner>
      </div>
    </form>
  </section>
</template>
