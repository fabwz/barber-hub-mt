<script setup lang="ts">
import { computed, nextTick, ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowRight, Eye, EyeOff } from 'lucide-vue-next'

import { isApiError } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import BaseCheckbox from '@/components/BaseCheckbox.vue'
import BrandLogo from '@/components/BrandLogo.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import PasswordRequirements, {
  type PasswordRequirement,
} from '@/components/PasswordRequirements.vue'
import TextField from '@/components/TextField.vue'
import { useFormErrors } from '@/composables/useFormErrors'
import { safeRedirect } from '@/router/guard'
import { useSessionStore } from '@/stores/session'

// Needed by KeepAlive in App.vue (matched by component name).
defineOptions({ name: 'RegisterView' })

type Field = 'name' | 'email' | 'phone' | 'password' | 'terms' | 'privacy'
const FIELD_ORDER: readonly Field[] = ['name', 'email', 'phone', 'password', 'terms', 'privacy']

// SE-07: at least 8 characters with an uppercase letter, a lowercase letter, a digit and a symbol.
const PASSWORD_REQUIREMENTS: PasswordRequirement[] = [
  { id: 'length', text: 'Mínimo 8 caracteres', test: (p) => p.length >= 8 },
  { id: 'upper', text: 'Una mayúscula', test: (p) => /\p{Lu}/u.test(p) },
  { id: 'lower', text: 'Una minúscula', test: (p) => /\p{Ll}/u.test(p) },
  { id: 'digit', text: 'Un número', test: (p) => /\d/.test(p) },
  { id: 'symbol', text: 'Un símbolo', test: (p) => /[^\p{L}\p{N}]/u.test(p) },
]
const PASSWORD_HINT = 'Mínimo 8 caracteres, una mayúscula, una minúscula, un número y un símbolo.'
const TERMS_ERROR = 'Debe aceptar los Términos y condiciones para crear su cuenta.'
const PRIVACY_ERROR = 'Debe aceptar la Política de privacidad para crear su cuenta.'

// Limits of the contract (RegistroDeCuenta).
const MAX_NAME = 120
const MAX_EMAIL = 255
// Phone: 8 digits shown as 1234-5678, so the field holds at most 9 characters.
const PHONE_DIGITS = 8
const MAX_PHONE_TEXT = 9

/** Letters (with accents), spaces and the marks real names use: apostrophe, hyphen and period. */
function cleanName(value: string): string {
  return value.replace(/[^\p{L}\p{M}\s'’.-]/gu, '')
}

function phoneDigits(value: string): string {
  return value.replace(/\D/g, '')
}

/** At most 8 digits, with the hyphen added by itself when the 5th digit is typed: 1234-5678. */
function cleanPhone(value: string): string {
  const digits = phoneDigits(value).slice(0, PHONE_DIGITS)
  return digits.length > 4 ? `${digits.slice(0, 4)}-${digits.slice(4)}` : digits
}

const EMAIL_EXAMPLE = 'Ejemplo: nombre@correo.cr.'

/** Says what the email is missing; the API still has the last word. Any ending is valid (.cr, .com, .co.cr…). */
function emailProblem(value: string): string {
  const text = value.trim()
  if (text === '') return 'Escriba su correo electrónico.'
  if (/\s/.test(text)) return 'El correo no puede llevar espacios.'
  if (!text.includes('@')) return `Al correo le falta el @. ${EMAIL_EXAMPLE}`
  const [local = '', domain = ''] = text.split('@')
  if (local === '') return `Al correo le falta lo que va antes del @. ${EMAIL_EXAMPLE}`
  if (!/^[^\s@.]+(?:\.[^\s@.]+)*\.[^\s@.]{2,}$/.test(domain)) {
    return `Al correo le falta el dominio. ${EMAIL_EXAMPLE}`
  }
  return ''
}

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const name = ref('')
const email = ref('')
const phone = ref('')
const password = ref('')
const showPassword = ref(false)
const acceptTerms = ref(false)
const acceptPrivacy = ref(false)
const loading = ref(false)
const attempted = ref(false)
const failureNotice = ref(false)
const announcement = ref('')

const errors = useFormErrors<Field>({
  nombre: 'name',
  correo: 'email',
  telefono: 'phone',
  contrasena: 'password',
  acepta_terminos: 'terms',
  acepta_privacidad: 'privacy',
})

const fields = {
  name: useTemplateRef<InstanceType<typeof TextField>>('nameField'),
  email: useTemplateRef<InstanceType<typeof TextField>>('emailField'),
  phone: useTemplateRef<InstanceType<typeof TextField>>('phoneField'),
  password: useTemplateRef<InstanceType<typeof TextField>>('passwordField'),
  terms: useTemplateRef<InstanceType<typeof BaseCheckbox>>('termsField'),
  privacy: useTemplateRef<InstanceType<typeof BaseCheckbox>>('privacyField'),
}

const loginLink = computed(() => ({
  name: 'login',
  query: route.query.redirect ? { redirect: route.query.redirect } : {},
}))

function validateLocally(): void {
  // Every field is required: say so before calling the API.
  if (name.value.trim() === '') errors.setField('name', 'Escriba su nombre completo.')
  const emailMessage = emailProblem(email.value)
  if (emailMessage !== '') errors.setField('email', emailMessage)
  if (phone.value.trim() === '') {
    errors.setField('phone', 'Escriba su teléfono.')
  } else if (phoneDigits(phone.value).length < PHONE_DIGITS) {
    errors.setField('phone', 'El teléfono debe tener 8 dígitos. Ejemplo: 1234-5678.')
  }
  if (!PASSWORD_REQUIREMENTS.every((r) => r.test(password.value))) {
    errors.setField(
      'password',
      password.value === '' ? 'Escriba una contraseña.' : 'La contraseña no cumple los requisitos.',
    )
  }
  if (!acceptTerms.value) errors.setField('terms', TERMS_ERROR)
  if (!acceptPrivacy.value) errors.setField('privacy', PRIVACY_ERROR)
}

async function focusFirstError(): Promise<void> {
  const first = errors.firstField(FIELD_ORDER)
  announcement.value = first ? 'Revise los campos marcados.' : ''
  await nextTick()
  if (first) fields[first].value?.focus()
}

function resetForm(): void {
  // The password must not stay in memory once the account exists.
  name.value = email.value = phone.value = password.value = ''
  acceptTerms.value = acceptPrivacy.value = false
  attempted.value = false
  showPassword.value = false
  errors.clear()
}

/**
 * Keeps only the allowed characters while typing or pasting. It cleans the input itself,
 * because the model may not change (and so would not redraw) when a character is dropped.
 */
function restrict(event: Event, clean: (value: string) => string, target: typeof name): void {
  const input = event.target as HTMLInputElement
  const cursor = input.selectionStart ?? input.value.length
  const before = input.value
  const after = clean(before)
  if (after !== before) {
    input.value = after
    const position = Math.max(0, cursor - (before.length - after.length))
    input.setSelectionRange(position, position)
  }
  target.value = after
}

function onNameInput(event: Event): void {
  restrict(event, cleanName, name)
  errors.clearField('name')
}

function onPhoneInput(event: Event): void {
  restrict(event, cleanPhone, phone)
  errors.clearField('phone')
}

async function submit(): Promise<void> {
  if (loading.value) return
  errors.clear()
  failureNotice.value = false
  attempted.value = true
  validateLocally()
  if (errors.firstField(FIELD_ORDER)) {
    await focusFirstError()
    return
  }

  loading.value = true
  try {
    await session.register({
      name: name.value,
      email: email.value,
      phone: phoneDigits(phone.value),
      password: password.value,
    })
    resetForm()
    const redirect = route.query.redirect
    if (session.isAuthenticated) {
      await router.replace(safeRedirect(redirect))
    } else {
      await router.replace({
        name: 'login',
        query: { registrado: '1', ...(redirect ? { redirect } : {}) },
      })
    }
  } catch (error) {
    if (!isApiError(error)) throw error
    errors.setFromApiError(error)
    failureNotice.value = errors.generalError.value !== ''
    // The fields are disabled while sending; enable them before moving the focus.
    loading.value = false
    await focusFirstError()
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
        <h1 class="text-2xl">Crear cuenta</h1>
        <p class="mt-2 text-fg-muted">
          Complete sus datos para reservar. Todos los campos y las dos casillas son obligatorios.
        </p>

        <p class="sr-only" aria-live="polite">{{ announcement }}</p>
        <NoticeBanner v-if="failureNotice" variant="error" class="mt-6">
          <p class="font-medium">No pudimos crear su cuenta.</p>
          <p class="mt-1 text-fg-muted">
            {{ errors.generalError.value }} Lo que escribió se mantiene.
          </p>
          <template #action>
            <BaseButton variant="secondary" @click="submit">Reintentar</BaseButton>
          </template>
        </NoticeBanner>

        <form novalidate class="mt-8" @submit.prevent="submit">
          <div class="flex flex-col gap-6" :class="loading ? 'opacity-60' : ''">
            <TextField
              ref="nameField"
              v-model="name"
              label="Nombre completo"
              name="name"
              autocomplete="name"
              placeholder="Laura Brenes Mora"
              :maxlength="MAX_NAME"
              :disabled="loading"
              :error="errors.fieldErrors.value.name"
              @input="onNameInput"
            />
            <TextField
              ref="emailField"
              v-model="email"
              label="Correo electrónico"
              type="email"
              name="email"
              autocomplete="email"
              inputmode="email"
              placeholder="nombre@correo.cr"
              :maxlength="MAX_EMAIL"
              :disabled="loading"
              :error="errors.fieldErrors.value.email"
              @input="errors.clearField('email')"
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
              :disabled="loading"
              :error="errors.fieldErrors.value.phone"
              @input="onPhoneInput"
            />
            <div>
              <div class="relative">
                <TextField
                  ref="passwordField"
                  v-model="password"
                  label="Contraseña"
                  :type="showPassword ? 'text' : 'password'"
                  name="password"
                  autocomplete="new-password"
                  placeholder="Cree una contraseña"
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
              <PasswordRequirements
                id="register-password-requirements"
                :requirements="PASSWORD_REQUIREMENTS"
                :password="password"
                :hint="PASSWORD_HINT"
                :failed="attempted"
              />
            </div>
          </div>

          <div class="mt-6 flex flex-col gap-1">
            <BaseCheckbox
              ref="termsField"
              v-model="acceptTerms"
              required
              :loading="loading"
              :error="errors.fieldErrors.value.terms"
              @update:model-value="errors.clearField('terms')"
            >
              <span class="text-fg-muted">Acepto los</span>{{ ' ' }}
              <RouterLink to="/terminos" class="font-medium no-underline">
                Términos y condiciones
              </RouterLink>
            </BaseCheckbox>
            <BaseCheckbox
              ref="privacyField"
              v-model="acceptPrivacy"
              required
              :loading="loading"
              :error="errors.fieldErrors.value.privacy"
              @update:model-value="errors.clearField('privacy')"
            >
              <span class="text-fg-muted">Acepto la</span>{{ ' ' }}
              <RouterLink to="/privacidad" class="font-medium no-underline">
                Política de privacidad
              </RouterLink>
            </BaseCheckbox>
          </div>

          <BaseButton type="submit" size="large" class="mt-8 w-full" :loading="loading">
            {{ loading ? 'Creando cuenta…' : 'Crear cuenta' }}
          </BaseButton>
        </form>

        <p class="mt-4 flex flex-wrap items-center justify-center gap-x-1 text-sm">
          <span class="text-fg-muted">¿Ya tiene cuenta?</span>
          <RouterLink
            :to="loginLink"
            class="inline-flex min-h-11 items-center gap-1.5 px-3 font-medium text-fg no-underline"
          >
            Ingresar
            <ArrowRight :size="16" :stroke-width="1.5" aria-hidden="true" />
          </RouterLink>
        </p>
      </div>
    </main>
  </div>
</template>
