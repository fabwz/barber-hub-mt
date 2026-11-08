<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowRight, Eye, EyeOff } from 'lucide-vue-next'

import { isApiError, NETWORK_ERROR_TYPE } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import BrandLogo from '@/components/BrandLogo.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import TextField from '@/components/TextField.vue'
import { useCountdown } from '@/composables/useCountdown'
import { useFormErrors } from '@/composables/useFormErrors'
import { safeRedirect } from '@/router/guard'
import { useSessionStore } from '@/stores/session'

type Field = 'email' | 'password'

// SE-05: after 5 failed attempts the API blocks for 15 minutes. Used only when Retry-After is missing.
const DEFAULT_LOCK_SECONDS = 15 * 60

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const email = ref('')
const password = ref('')
const showPassword = ref(false)
const loading = ref(false)
const badCredentials = ref(false)
const networkFailed = ref(false)

const countdown = useCountdown()
const errors = useFormErrors<Field>({ correo: 'email', contrasena: 'password' })

const justRegistered = computed(() => route.query.registrado === '1')
const registerLink = computed(() => ({
  name: 'register',
  query: route.query.redirect ? { redirect: route.query.redirect } : {},
}))

async function submit(): Promise<void> {
  if (loading.value || countdown.active.value) return
  errors.clear()
  badCredentials.value = false
  networkFailed.value = false
  loading.value = true
  try {
    await session.login(email.value, password.value)
    password.value = ''
    showPassword.value = false
    await router.replace(safeRedirect(route.query.redirect))
  } catch (error) {
    if (!isApiError(error)) throw error
    if (error.status === 429) {
      countdown.start(error.retryAfter ?? DEFAULT_LOCK_SECONDS)
    } else if (error.type === NETWORK_ERROR_TYPE) {
      networkFailed.value = true
    } else if (error.status === 401) {
      // One generic message: it never says whether the email or the password failed.
      badCredentials.value = true
    } else {
      errors.setFromApiError(error)
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <header class="flex h-16 flex-none items-center px-5 md:px-8">
      <BrandLogo />
    </header>
    <main class="px-5 pt-4 pb-10 md:px-8 md:pt-12" :aria-busy="loading ? 'true' : undefined">
      <div class="mx-auto w-full max-w-97.5 md:max-w-110">
        <h1 class="text-2xl">Iniciar sesión</h1>
        <p class="mt-2 text-fg-muted">Ingrese con su correo y su contraseña.</p>

        <div class="mt-6 flex flex-col gap-4" aria-live="polite">
          <NoticeBanner v-if="justRegistered && !countdown.active.value" variant="success">
            Su cuenta ha sido registrada con éxito. Ingrese con su correo y su contraseña.
          </NoticeBanner>
          <NoticeBanner v-if="countdown.active.value" variant="error">
            <p class="font-medium">
              Demasiados intentos. Intente de nuevo en
              <span role="timer" class="tabular-nums">{{ countdown.formatted.value }}</span>
            </p>
            <p class="mt-1 text-fg-muted">
              Por seguridad, el ingreso se habilita cuando termina la cuenta regresiva.
            </p>
          </NoticeBanner>
          <NoticeBanner v-if="badCredentials" variant="error">
            <p class="font-medium">El correo o la contraseña no son correctos.</p>
            <p class="mt-1 text-fg-muted">Revise los datos e intente de nuevo.</p>
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
          <div class="flex flex-col gap-6" :class="loading ? 'opacity-60' : ''">
            <TextField
              v-model="email"
              label="Correo electrónico"
              type="email"
              name="email"
              autocomplete="email"
              inputmode="email"
              placeholder="nombre@correo.cr"
              :disabled="loading"
              :error="errors.fieldErrors.value.email"
              @input="errors.clearField('email')"
            />
            <div class="relative">
              <TextField
                v-model="password"
                label="Contraseña"
                :type="showPassword ? 'text' : 'password'"
                name="password"
                autocomplete="current-password"
                placeholder="Su contraseña"
                class="pr-12!"
                :disabled="loading"
                :error="errors.fieldErrors.value.password"
                @input="errors.clearField('password')"
              />
              <!-- Aligned with the 48 px input: the label above it is 20 px plus the 8 px gap. -->
              <button
                type="button"
                class="absolute top-7 right-0 grid size-12 place-items-center rounded-field text-fg-muted transition-colors duration-150 ease-out hover:text-fg disabled:cursor-not-allowed disabled:text-fg-subtle"
                aria-label="Mostrar contraseña"
                :aria-pressed="showPassword ? 'true' : 'false'"
                :disabled="loading"
                @click="showPassword = !showPassword"
              >
                <EyeOff v-if="showPassword" :size="20" :stroke-width="1.5" aria-hidden="true" />
                <Eye v-else :size="20" :stroke-width="1.5" aria-hidden="true" />
              </button>
            </div>
          </div>
          <BaseButton
            type="submit"
            size="large"
            class="mt-8 w-full"
            :loading="loading"
            :disabled="countdown.active.value"
          >
            {{ loading ? 'Ingresando…' : 'Ingresar' }}
          </BaseButton>
        </form>

        <p class="mt-4 flex flex-wrap items-center justify-center gap-x-1 text-sm">
          <span class="text-fg-muted">¿No tiene cuenta?</span>
          <RouterLink
            :to="registerLink"
            class="inline-flex min-h-11 items-center gap-1.5 px-3 font-medium text-fg no-underline"
          >
            Crear cuenta
            <ArrowRight :size="16" :stroke-width="1.5" aria-hidden="true" />
          </RouterLink>
        </p>
      </div>
    </main>
  </div>
</template>
