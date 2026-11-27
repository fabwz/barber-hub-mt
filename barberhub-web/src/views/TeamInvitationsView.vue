<script setup lang="ts">
import { computed, onMounted, ref, useTemplateRef } from 'vue'
import { useRoute } from 'vue-router'
import { Send } from 'lucide-vue-next'

import { NETWORK_ERROR_TYPE, isApiError } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import BaseCheckbox from '@/components/BaseCheckbox.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import TextField from '@/components/TextField.vue'
import { colones, dateOf, durationLabel, formatDateWithYear, timeOf } from '@/composables/format'
import { useFormErrors } from '@/composables/useFormErrors'
import { useInvitationStore, type Invitation } from '@/stores/invitations'
import { useServiceAdminStore } from '@/stores/services'

// «Invitaciones» (RF-06; lámina 33t a 33ab): the owner invites an email with the services the person will offer, sees the
// invitations nobody has accepted yet and cancels them. The link is valid 7 days and works once (UP-04); the token only
// travels in the email, so this screen never sees it. Inviting an email again replaces its pending invitation.
type Field = 'email'

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const EMAIL_MESSAGE = 'Escriba un correo válido, como nombre@correo.cr.'
const DAY_MS = 24 * 60 * 60 * 1000

const route = useRoute()
const invitations = useInvitationStore()
const services = useServiceAdminStore()

const barberia = computed(() => String(route.params.barberia))

onMounted(() => {
  void invitations.load(barberia.value)
  void services.load(barberia.value)
})

const email = ref('')
const selected = ref<string[]>([])
const sending = ref(false)
const servicesError = ref('')
const done = ref('')
const networkFailed = ref(false)
const errors = useFormErrors<Field>({ correo: 'email' })
const emailField = useTemplateRef<InstanceType<typeof TextField>>('emailField')

async function submit(): Promise<void> {
  if (sending.value) return
  errors.clear()
  servicesError.value = ''
  networkFailed.value = false
  done.value = ''

  const address = email.value.trim()
  if (!EMAIL_PATTERN.test(address)) {
    errors.setField('email', EMAIL_MESSAGE)
    emailField.value?.focus()
    return
  }

  sending.value = true
  try {
    const invitation = await invitations.invite(barberia.value, {
      correo: address,
      servicios: selected.value,
    })
    done.value = `Invitación enviada a ${invitation.correo}. Vence en ${daysLabel(invitation)}.`
    email.value = ''
    selected.value = []
  } catch (error) {
    if (!isApiError(error)) throw error
    if (error.status === 401) return
    if (error.type === NETWORK_ERROR_TYPE) {
      networkFailed.value = true
      return
    }
    errors.setFromApiError(error)
    // A service the barbershop does not have comes as «servicios.N»: the list answers for all of them.
    const rejected = Object.entries(error.errors).find(([field]) => field.startsWith('servicios'))
    if (rejected !== undefined) {
      servicesError.value = rejected[1].join(' ')
      errors.generalError.value = ''
    }
    if (errors.fieldErrors.value.email !== undefined) emailField.value?.focus()
  } finally {
    sending.value = false
  }
}

const asking = ref<Invitation | null>(null)
const askOpen = ref(false)
const cancelling = ref(false)
const cancelFailed = ref(false)

function askToCancel(invitation: Invitation): void {
  cancelFailed.value = false
  asking.value = invitation
  askOpen.value = true
}

async function confirmCancel(): Promise<void> {
  if (asking.value === null) return
  cancelling.value = true
  cancelFailed.value = false
  done.value = ''
  try {
    await invitations.cancel(barberia.value, asking.value.id)
    done.value = 'Invitación cancelada. El enlace dejó de funcionar.'
  } catch (error) {
    if (isApiError(error) && error.status === 401) return
    if (isApiError(error) && error.status === 404) {
      // It was accepted, expired or cancelled meanwhile: it is not pending any more, so the list is read again.
      void invitations.load(barberia.value)
    } else {
      cancelFailed.value = true
    }
  } finally {
    cancelling.value = false
    askOpen.value = false
    asking.value = null
  }
}

/** «jue 5 nov 2026, 09:14». */
function momentLabel(moment: string): string {
  return `${formatDateWithYear(dateOf(moment))}, ${timeOf(moment)}`
}

/** «7 días» or «1 día»: whole days that are left until the link stops working, at least one while it works. */
function daysLabel(invitation: Invitation): string {
  const days = Math.max(1, Math.ceil((Date.parse(invitation.vence) - Date.now()) / DAY_MS))
  return `${days} ${days === 1 ? 'día' : 'días'}`
}
</script>

<template>
  <section aria-labelledby="invitations-title" :aria-busy="invitations.status === 'loading'">
    <div>
      <h2 id="invitations-title" class="text-lg font-medium">Invitar por correo</h2>
      <p class="mt-1 text-sm text-fg-muted">El enlace es válido por 7 días y de un solo uso.</p>
    </div>

    <div class="mt-6 flex flex-col gap-4" aria-live="polite">
      <NoticeBanner v-if="done">{{ done }}</NoticeBanner>
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
      <NoticeBanner v-if="cancelFailed" variant="error">
        <p class="font-medium">No pudimos cancelar la invitación.</p>
        <p class="mt-1 text-fg-muted">Inténtelo de nuevo.</p>
      </NoticeBanner>
    </div>

    <form novalidate class="mt-6 flex max-w-xl flex-col gap-5" @submit.prevent="submit">
      <div class="flex flex-col gap-2">
        <TextField
          ref="emailField"
          v-model="email"
          label="Correo de la persona"
          type="email"
          name="email"
          autocomplete="off"
          aria-describedby="email-help"
          :disabled="sending"
          :error="errors.fieldErrors.value.email"
          @input="errors.clearField('email')"
        />
        <p id="email-help" class="text-sm text-fg-muted">
          Recibirá un enlace para crear su propia contraseña.
        </p>
      </div>

      <fieldset class="flex flex-col gap-2" :disabled="sending">
        <legend class="text-sm font-medium text-fg">Servicios que ofrecerá</legend>
        <SkeletonBlock
          v-if="services.status === 'idle' || services.status === 'loading'"
          shape="row"
          :count="2"
          label="Cargando los servicios"
        />
        <ErrorState
          v-else-if="services.status === 'error'"
          message="No pudimos cargar los servicios"
          description="Puede enviar la invitación sin servicios y asignarlos después."
          @retry="services.load(barberia)"
        />
        <p v-else-if="services.items.length === 0" class="text-sm text-fg-muted">
          Todavía no hay servicios. Podrá asignarlos cuando la persona acepte.
        </p>
        <ul v-else class="flex flex-col">
          <li v-for="service in services.items" :key="service.id" class="border-b border-line">
            <BaseCheckbox
              :model-value="selected.includes(service.id)"
              @update:model-value="
                (checked: boolean) => {
                  servicesError = ''
                  selected = checked
                    ? [...selected, service.id]
                    : selected.filter((id) => id !== service.id)
                }
              "
            >
              <span class="block font-medium text-fg">{{ service.nombre }}</span>
              <span class="block text-sm text-fg-muted tabular-nums"
                >{{ durationLabel(service.duracion_minutos) }} · {{ colones(service.precio) }}</span
              >
            </BaseCheckbox>
          </li>
        </ul>
        <p v-if="servicesError" role="alert" class="text-sm text-danger">{{ servicesError }}</p>
      </fieldset>

      <div>
        <BaseButton type="submit" size="large" :loading="sending">
          <Send :size="16" :stroke-width="1.5" aria-hidden="true" />
          {{ sending ? 'Enviando' : 'Enviar invitación' }}
        </BaseButton>
      </div>
    </form>

    <div class="mt-10">
      <h2 class="text-lg font-medium">Invitaciones pendientes</h2>
      <p class="mt-1 text-sm text-fg-muted">Aún no aceptadas.</p>

      <div class="mt-4">
        <SkeletonBlock
          v-if="
            invitations.status === 'idle' ||
            (invitations.status === 'loading' && invitations.items.length === 0)
          "
          shape="row"
          :count="2"
          label="Cargando las invitaciones"
        />
        <ErrorState
          v-else-if="invitations.status === 'error'"
          message="No pudimos cargar las invitaciones"
          description="Revise su conexión e inténtelo de nuevo."
          @retry="invitations.load(barberia)"
        />
        <EmptyState
          v-else-if="invitations.items.length === 0"
          title="No hay invitaciones pendientes."
          description="Cuando invite a alguien, aparecerá aquí hasta que cree su contraseña."
        />
        <ul v-else class="flex flex-col" aria-label="Invitaciones pendientes">
          <li
            v-for="invitation in invitations.items"
            :key="invitation.id"
            class="flex flex-wrap items-center gap-x-4 gap-y-3 border-b border-line py-3"
            :data-testid="`invitation-${invitation.id}`"
          >
            <div class="min-w-0 flex-1 basis-56">
              <p class="truncate font-medium text-fg">{{ invitation.correo }}</p>
              <p class="text-sm text-fg-muted tabular-nums">
                Enviada {{ momentLabel(invitation.enviada) }}
              </p>
              <p class="text-sm text-fg-muted tabular-nums">
                Vence en {{ daysLabel(invitation) }} · {{ momentLabel(invitation.vence) }}
              </p>
            </div>
            <BaseButton
              variant="secondary"
              :disabled="cancelling"
              :aria-label="`Cancelar la invitación a ${invitation.correo}`"
              @click="askToCancel(invitation)"
              >Cancelar invitación</BaseButton
            >
          </li>
        </ul>
      </div>
    </div>

    <ConfirmDialog
      v-model="askOpen"
      :title="`¿Cancelar la invitación a ${asking?.correo ?? ''}?`"
      description="El enlace dejará de funcionar. Podrá enviar otra invitación cuando quiera."
      cancel-label="No, mantenerla"
      confirm-label="Sí, cancelar invitación"
      :loading="cancelling"
      @confirm="confirmCancel"
    />
  </section>
</template>
