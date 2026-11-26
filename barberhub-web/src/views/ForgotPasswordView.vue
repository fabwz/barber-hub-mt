<script setup lang="ts">
import { ref, useTemplateRef } from 'vue'
import { ArrowLeft } from 'lucide-vue-next'

import { api, isApiError, NETWORK_ERROR_TYPE } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import BrandLogo from '@/components/BrandLogo.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import PreferencesLink from '@/components/PreferencesLink.vue'
import TextField from '@/components/TextField.vue'
import { useCountdown } from '@/composables/useCountdown'
import { useFormErrors } from '@/composables/useFormErrors'

// «Olvidé mi contraseña» (RF-41). Pending the sheet: it follows the columns of the login (DESIGN.md).
type Field = 'email'

// SE-06: 5 requests per hour per email and per address. Used only when Retry-After is missing.
const DEFAULT_WAIT_SECONDS = 60 * 60

const email = ref('')
const sending = ref(false)
const sent = ref(false)
const networkFailed = ref(false)
const emailField = useTemplateRef<InstanceType<typeof TextField>>('emailField')

const countdown = useCountdown()
const errors = useFormErrors<Field>({ correo: 'email' })

async function submit(): Promise<void> {
  if (sending.value || countdown.active.value) return
  errors.clear()
  networkFailed.value = false
  if (email.value.trim() === '') {
    errors.setField('email', 'Escriba su correo electrónico.')
    emailField.value?.focus()
    return
  }
  sending.value = true
  try {
    await api.POST('/contrasena/recuperacion', { body: { correo: email.value.trim() } })
    // The same notice whether or not the email has an account: the API answers 202 either way.
    sent.value = true
  } catch (error) {
    if (!isApiError(error)) throw error
    if (error.status === 429) {
      countdown.start(error.retryAfter ?? DEFAULT_WAIT_SECONDS)
    } else if (error.type === NETWORK_ERROR_TYPE) {
      networkFailed.value = true
    } else {
      errors.setFromApiError(error)
    }
  } finally {
    sending.value = false
  }
}

/** Back to the empty form, e.g. to correct a mistyped email. */
function startOver(): void {
  sent.value = false
  email.value = ''
  errors.clear()
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
        <h1 class="text-2xl">Olvidé mi contraseña</h1>

        <template v-if="!sent">
          <p class="mt-2 text-fg-muted">
            Escriba el correo de su cuenta. Le enviaremos un enlace para crear una contraseña nueva.
          </p>

          <div class="mt-6 flex flex-col gap-4" aria-live="polite">
            <NoticeBanner v-if="countdown.active.value" variant="error">
              <p class="font-medium">
                Demasiadas solicitudes. Intente de nuevo en
                <span role="timer" class="tabular-nums">{{ countdown.formatted.value }}</span>
              </p>
              <p class="mt-1 text-fg-muted">
                Por seguridad, solo se aceptan 5 solicitudes por hora. El botón se habilita cuando
                termina la cuenta regresiva.
              </p>
            </NoticeBanner>
            <NoticeBanner v-if="networkFailed" variant="error">
              <p class="font-medium">No pudimos comunicarnos con el servidor.</p>
              <p class="mt-1 text-fg-muted">Revise su conexión e intente de nuevo.</p>
              <template #action>
                <BaseButton variant="secondary" @click="submit">Reintentar</BaseButton>
              </template>
            </NoticeBanner>
            <NoticeBanner v-if="errors.generalError.value" variant="error">
              {{ errors.generalError.value }}
            </NoticeBanner>
          </div>

          <form novalidate class="mt-8" @submit.prevent="submit">
            <div :class="sending ? 'opacity-60' : ''">
              <TextField
                ref="emailField"
                v-model="email"
                label="Correo electrónico"
                type="email"
                name="email"
                autocomplete="email"
                inputmode="email"
                placeholder="nombre@correo.cr"
                :disabled="sending"
                :error="errors.fieldErrors.value.email"
                @input="errors.clearField('email')"
              />
            </div>
            <BaseButton
              type="submit"
              size="large"
              class="mt-8 w-full"
              :loading="sending"
              :disabled="countdown.active.value"
            >
              {{ sending ? 'Enviando' : 'Enviar enlace' }}
            </BaseButton>
          </form>
        </template>

        <template v-else>
          <!-- The answer is the same for any email: it never says whether the account exists. -->
          <NoticeBanner variant="success" class="mt-6">
            <p class="font-medium">Revise su correo.</p>
            <p class="mt-1 text-fg-muted">
              Si hay una cuenta con ese correo, le enviamos un enlace para crear una contraseña
              nueva. El enlace vale 60 minutos y se puede usar una sola vez. Si no lo ve, revise la
              carpeta de correo no deseado.
            </p>
          </NoticeBanner>
          <BaseButton variant="secondary" size="large" class="mt-8 w-full" @click="startOver">
            Usar otro correo
          </BaseButton>
        </template>

        <p class="mt-4 flex justify-center text-sm">
          <RouterLink
            :to="{ name: 'login' }"
            class="inline-flex min-h-11 items-center gap-1.5 px-3 font-medium text-fg no-underline"
          >
            <ArrowLeft :size="16" :stroke-width="1.5" aria-hidden="true" />
            Volver a iniciar sesión
          </RouterLink>
        </p>
      </div>
    </main>
  </div>
</template>
