<script setup lang="ts">
import { nextTick, onMounted, ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { api, isApiError, NETWORK_ERROR_TYPE } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import BrandLogo from '@/components/BrandLogo.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import PasswordChecklist from '@/components/PasswordChecklist.vue'
import PasswordField from '@/components/PasswordField.vue'
import PreferencesLink from '@/components/PreferencesLink.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import TextField from '@/components/TextField.vue'
import { meetsPasswordPolicy } from '@/composables/passwordPolicy'
import { useFormErrors } from '@/composables/useFormErrors'
import { useSessionStore } from '@/stores/session'

// «Aceptar invitación» (RF-06, UP-04). Pending the sheet: it uses the fields and the notices of «Crear nueva contraseña»
// (DESIGN.md). The link of the email names the barbershop, so the screen only asks for the token: it asks the API what
// the link invites to (without spending it) and then asks for what is missing. An email with no account chooses a name
// and a password under SE-07; one that already has an account only accepts, and its password does not change.
type Field = 'name' | 'next' | 'confirm'
type Stage = 'loading' | 'error' | 'invalid' | 'form' | 'done'

interface Invitation {
  barberia: string
  correo: string
  cuenta_existente: boolean
}

const FIELD_ORDER: readonly Field[] = ['name', 'next', 'confirm']
const MISMATCH = 'Las contraseñas no coinciden.'

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

// The token comes in the address of the email. It is kept in memory and taken out of the address at once,
// so it stays out of the history, of the bookmarks and of the Referer of any later request.
const token = ref('')
const stage = ref<Stage>('loading')
const invitation = ref<Invitation | null>(null)
const name = ref('')
const next = ref('')
const confirmation = ref('')
const sending = ref(false)
const attempted = ref(false)
const networkFailed = ref(false)
const announcement = ref('')

const errors = useFormErrors<Field>({ nombre: 'name', contrasena: 'next' })

const fields = {
  name: useTemplateRef<InstanceType<typeof TextField>>('nameField'),
  next: useTemplateRef<InstanceType<typeof PasswordField>>('nextField'),
  confirm: useTemplateRef<InstanceType<typeof PasswordField>>('confirmField'),
}

onMounted(async () => {
  const value = route.query.token
  const candidate = Array.isArray(value) ? value[0] : value
  token.value = typeof candidate === 'string' ? candidate : ''
  if (route.query.token !== undefined) await router.replace({ name: 'accept-invitation' })
  // An empty address is a link that arrived incomplete: there is nothing to accept.
  if (token.value === '') stage.value = 'invalid'
  else await load()
})

/** Reads what the link invites to; nothing is spent. */
async function load(): Promise<void> {
  stage.value = 'loading'
  try {
    const { data } = await api.POST('/invitaciones/consulta', { body: { token: token.value } })
    invitation.value = data!.data
    stage.value = 'form'
  } catch (error) {
    if (!isApiError(error)) throw error
    if (error.status === 422 && error.errors.token !== undefined) {
      // Unknown, expired, used or cancelled: the API does not say which, and neither does the screen.
      token.value = ''
      stage.value = 'invalid'
    } else {
      stage.value = 'error'
    }
  }
}

function validateLocally(): void {
  if (invitation.value?.cuenta_existente) return
  if (name.value.trim() === '') errors.setField('name', 'Escriba su nombre completo.')
  if (!meetsPasswordPolicy(next.value)) {
    errors.setField(
      'next',
      next.value === '' ? 'Escriba la contraseña.' : 'La contraseña no cumple los requisitos.',
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
    await api.POST('/invitaciones/aceptacion', {
      body: invitation.value?.cuenta_existente
        ? { token: token.value }
        : { token: token.value, nombre: name.value.trim(), contrasena: next.value },
    })
    // Neither the password nor the token stay in memory once they were used.
    next.value = confirmation.value = token.value = ''
    stage.value = 'done'
  } catch (error) {
    if (!isApiError(error)) throw error
    if (error.status === 401) return
    if (error.type === NETWORK_ERROR_TYPE) {
      networkFailed.value = true
    } else if (error.status === 422 && error.errors.token !== undefined) {
      next.value = confirmation.value = token.value = ''
      stage.value = 'invalid'
    } else {
      // A 409 says the person already has a role in the barbershop (UP-07); the rest are the fields of the form.
      errors.setFromApiError(error)
      sending.value = false
      await focusFirstError()
    }
  } finally {
    sending.value = false
  }
}

const LINK_CLASS =
  'mt-8 inline-flex min-h-12 w-full items-center justify-center rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover active:bg-btn-active'
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <header class="flex h-16 flex-none items-center justify-between gap-2 px-5 md:px-8">
      <BrandLogo />
      <PreferencesLink />
    </header>
    <main
      class="px-5 pt-4 pb-10 md:px-8 md:pt-12"
      :aria-busy="stage === 'loading' || sending ? 'true' : undefined"
    >
      <div class="mx-auto w-full max-w-97.5 md:max-w-110">
        <!-- Loading: reading what the link invites to. -->
        <template v-if="stage === 'loading'">
          <h1 class="text-2xl">Invitación</h1>
          <SkeletonBlock class="mt-6" shape="block" label="Cargando la invitación" />
        </template>

        <!-- Error: the API could not be reached. The link was not spent. -->
        <template v-else-if="stage === 'error'">
          <h1 class="text-2xl">Invitación</h1>
          <ErrorState
            class="mt-6"
            message="No pudimos cargar la invitación"
            description="Revise su conexión e inténtelo de nuevo."
            @retry="load"
          />
        </template>

        <!-- Empty state: the link arrived without a token, or the API refused it. -->
        <template v-else-if="stage === 'invalid'">
          <h1 class="text-2xl">Enlace no válido</h1>
          <NoticeBanner variant="error" class="mt-6">
            <p class="font-medium">Este enlace no sirve o ya venció.</p>
            <p class="mt-1 text-fg-muted">
              Cada enlace vale 7 días y se puede usar una sola vez. Pida a la barbería que le envíe
              otra invitación.
            </p>
          </NoticeBanner>
          <RouterLink :to="{ name: 'login' }" :class="LINK_CLASS">Ir a iniciar sesión</RouterLink>
        </template>

        <template v-else-if="stage === 'done'">
          <h1 class="text-2xl">Invitación aceptada</h1>
          <NoticeBanner variant="success" class="mt-6">
            <p class="font-medium">Ya forma parte del equipo de {{ invitation?.barberia }}.</p>
            <p class="mt-1 text-fg-muted">
              {{
                invitation?.cuenta_existente
                  ? 'Ingrese con su contraseña de siempre.'
                  : 'Su cuenta está activa. Ingrese con su correo y la contraseña que acaba de crear.'
              }}
            </p>
          </NoticeBanner>
          <RouterLink
            :to="session.isAuthenticated ? { name: 'shops' } : { name: 'login' }"
            :class="LINK_CLASS"
          >
            {{ session.isAuthenticated ? 'Ir a mis negocios' : 'Ir a iniciar sesión' }}
          </RouterLink>
        </template>

        <template v-else-if="invitation">
          <h1 class="text-2xl">{{ invitation.barberia }} le invita a su equipo</h1>
          <p class="mt-2 text-fg-muted">
            {{
              invitation.cuenta_existente
                ? `Ya existe una cuenta con ${invitation.correo}. Acepte para unirse al equipo; su contraseña no cambia.`
                : `Cree su cuenta con ${invitation.correo} para unirse al equipo.`
            }}
          </p>

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
            <div
              v-if="!invitation.cuenta_existente"
              class="flex flex-col gap-5"
              :class="sending ? 'opacity-60' : ''"
            >
              <TextField
                ref="nameField"
                v-model="name"
                label="Nombre completo"
                name="name"
                autocomplete="name"
                :disabled="sending"
                :error="errors.fieldErrors.value.name"
                @input="errors.clearField('name')"
              />
              <PasswordField
                ref="nextField"
                v-model="next"
                label="Contraseña"
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
                label="Confirmar contraseña"
                name="confirm-password"
                autocomplete="new-password"
                :disabled="sending"
                :error="errors.fieldErrors.value.confirm"
                @input="errors.clearField('confirm')"
              />
            </div>
            <BaseButton type="submit" size="large" class="mt-8 w-full" :loading="sending">
              {{
                sending
                  ? 'Guardando'
                  : invitation.cuenta_existente
                    ? 'Aceptar invitación'
                    : 'Crear cuenta y unirme'
              }}
            </BaseButton>
          </form>
        </template>
      </div>
    </main>
  </div>
</template>
