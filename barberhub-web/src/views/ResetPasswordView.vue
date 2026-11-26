<script setup lang="ts">
import { nextTick, onMounted, ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { api, isApiError, NETWORK_ERROR_TYPE } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import BrandLogo from '@/components/BrandLogo.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import PasswordChecklist from '@/components/PasswordChecklist.vue'
import PasswordField from '@/components/PasswordField.vue'
import PreferencesLink from '@/components/PreferencesLink.vue'
import { meetsPasswordPolicy } from '@/composables/passwordPolicy'
import { useFormErrors } from '@/composables/useFormErrors'
import { useSessionStore } from '@/stores/session'

// «Crear nueva contraseña» (RF-41). Pending the sheet: it uses the fields of «Seguridad» (DESIGN.md).
type Field = 'next' | 'confirm'
type Stage = 'form' | 'invalid' | 'done'

const FIELD_ORDER: readonly Field[] = ['next', 'confirm']
const MISMATCH = 'Las contraseñas no coinciden.'

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

// The token comes in the address of the email. It is kept in memory and taken out of the address at once,
// so it stays out of the history, of the bookmarks and of the Referer of any later request.
const token = ref('')
const stage = ref<Stage>('form')
const next = ref('')
const confirmation = ref('')
const sending = ref(false)
const attempted = ref(false)
const networkFailed = ref(false)
const announcement = ref('')

const errors = useFormErrors<Field>({ contrasena_nueva: 'next' })

const fields = {
  next: useTemplateRef<InstanceType<typeof PasswordField>>('nextField'),
  confirm: useTemplateRef<InstanceType<typeof PasswordField>>('confirmField'),
}

onMounted(async () => {
  const value = route.query.token
  const candidate = Array.isArray(value) ? value[0] : value
  token.value = typeof candidate === 'string' ? candidate : ''
  // An empty address is a link that arrived incomplete: there is nothing to reset with.
  if (token.value === '') stage.value = 'invalid'
  if (route.query.token !== undefined) await router.replace({ name: 'reset-password' })
})

function validateLocally(): void {
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

async function submit(): Promise<void> {
  if (sending.value) return
  errors.clear()
  networkFailed.value = false
  attempted.value = true
  validateLocally()
  if (errors.firstField(FIELD_ORDER)) {
    await focusFirstError()
    return
  }

  sending.value = true
  try {
    await api.POST('/contrasena/restablecimiento', {
      body: { token: token.value, contrasena_nueva: next.value },
    })
    // No password stays in memory once it has been used, nor the token, which is spent.
    next.value = confirmation.value = token.value = ''
    // The API closed every session of the account; this browser's copy is dropped too.
    if (session.isAuthenticated) session.clear()
    stage.value = 'done'
  } catch (error) {
    if (!isApiError(error)) throw error
    if (error.type === NETWORK_ERROR_TYPE) {
      networkFailed.value = true
    } else if (error.status === 422 && error.errors.token !== undefined) {
      // Unknown, expired or already used: the API does not say which, and neither does the screen.
      next.value = confirmation.value = token.value = ''
      stage.value = 'invalid'
    } else {
      errors.setFromApiError(error)
      sending.value = false
      await focusFirstError()
    }
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <header class="flex h-16 flex-none items-center justify-between gap-2 px-5 md:px-8">
      <BrandLogo />
      <PreferencesLink />
    </header>
    <main class="px-5 pt-4 pb-10 md:px-8 md:pt-12" :aria-busy="sending ? 'true' : undefined">
      <div class="mx-auto w-full max-w-97.5 md:max-w-110">
        <!-- Empty state: the link arrived without a token, or the API refused it. -->
        <template v-if="stage === 'invalid'">
          <h1 class="text-2xl">Enlace no válido</h1>
          <NoticeBanner variant="error" class="mt-6">
            <p class="font-medium">Este enlace no sirve o ya venció.</p>
            <p class="mt-1 text-fg-muted">
              Cada enlace vale 60 minutos y se puede usar una sola vez. Solicite uno nuevo para
              continuar.
            </p>
          </NoticeBanner>
          <RouterLink
            :to="{ name: 'forgot-password' }"
            class="mt-8 inline-flex min-h-12 w-full items-center justify-center rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover active:bg-btn-active"
          >
            Solicitar un enlace nuevo
          </RouterLink>
        </template>

        <template v-else-if="stage === 'done'">
          <h1 class="text-2xl">Contraseña actualizada</h1>
          <NoticeBanner variant="success" class="mt-6">
            <p class="font-medium">Su contraseña se actualizó.</p>
            <p class="mt-1 text-fg-muted">
              Por seguridad cerramos sus sesiones abiertas. Ingrese con su contraseña nueva.
            </p>
          </NoticeBanner>
          <RouterLink
            :to="{ name: 'login' }"
            class="mt-8 inline-flex min-h-12 w-full items-center justify-center rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover active:bg-btn-active"
          >
            Ir a iniciar sesión
          </RouterLink>
        </template>

        <template v-else>
          <h1 class="text-2xl">Crear nueva contraseña</h1>
          <p class="mt-2 text-fg-muted">Elija una contraseña nueva para su cuenta.</p>

          <div class="mt-6 flex flex-col gap-4" aria-live="polite">
            <NoticeBanner v-if="networkFailed" variant="error">
              <p class="font-medium">No pudimos comunicarnos con el servidor.</p>
              <p class="mt-1 text-fg-muted">
                Revise su conexión e intente de nuevo. Lo que escribió se mantiene.
              </p>
              <template #action>
                <BaseButton variant="secondary" @click="submit">Reintentar</BaseButton>
              </template>
            </NoticeBanner>
            <NoticeBanner v-if="errors.generalError.value" variant="error">
              {{ errors.generalError.value }}
            </NoticeBanner>
          </div>

          <form novalidate class="mt-8" @submit.prevent="submit">
            <p class="sr-only" aria-live="polite">{{ announcement }}</p>
            <div class="flex flex-col gap-5" :class="sending ? 'opacity-60' : ''">
              <PasswordField
                ref="nextField"
                v-model="next"
                label="Contraseña nueva"
                name="new-password"
                autocomplete="new-password"
                aria-describedby="password-checklist"
                :disabled="sending"
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
                :disabled="sending"
                :error="errors.fieldErrors.value.confirm"
                @input="errors.clearField('confirm')"
              />
            </div>
            <BaseButton type="submit" size="large" class="mt-8 w-full" :loading="sending">
              {{ sending ? 'Guardando' : 'Crear contraseña nueva' }}
            </BaseButton>
          </form>
        </template>
      </div>
    </main>
  </div>
</template>
