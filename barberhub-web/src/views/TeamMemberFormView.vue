<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { Copy } from 'lucide-vue-next'

import BackLink from '@/components/BackLink.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseCheckbox from '@/components/BaseCheckbox.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import TemporaryPasswordResult from '@/components/TemporaryPasswordResult.vue'
import TextField from '@/components/TextField.vue'
import WorkDayEditor from '@/components/WorkDayEditor.vue'
import { colones, durationLabel } from '@/composables/format'
import { useCollaboratorForm, type FormMode } from '@/composables/useCollaboratorForm'
import { hoursLabel } from '@/composables/weekdays'
import { useScheduleStore } from '@/stores/schedule'
import { useServiceAdminStore } from '@/stores/services'
import { useTeamStore } from '@/stores/team'

// «Nuevo colaborador», «Crear mi perfil de colaborador» y «Editar» (RF-05, CO-01, CO-02, HO-02; lámina 33). One form
// for the three: the account data (only for a new one), the services and the schedule with breaks. The mode comes from
// the route. A new account is the account, its services and its schedule, three calls: the form keeps what already
// exists and repeats only what is missing (useCollaboratorForm).
const route = useRoute()
const router = useRouter()
const team = useTeamStore()
const services = useServiceAdminStore()
const schedule = useScheduleStore()

const barberia = computed(() => String(route.params.barberia))
const mode: FormMode =
  route.name === 'staff-team-own-profile' ? 'own' : route.params.colaborador ? 'edit' : 'create'

const form = useCollaboratorForm(barberia.value, mode)
const membersLink = computed(() => ({
  name: 'staff-team-members',
  params: { barberia: barberia.value },
}))

const loading = ref(false)
const notFound = ref(false)

async function load(): Promise<void> {
  notFound.value = false
  loading.value = true
  await Promise.all([
    services.load(barberia.value),
    // The schedule of the shop only gives the hint «Barbería: 09:00–19:00»; the form works without it.
    schedule.load(barberia.value),
    mode === 'edit' && (team.shopId !== barberia.value || team.items.length === 0)
      ? team.load(barberia.value)
      : Promise.resolve(),
  ])
  if (mode === 'edit') {
    const found = team.items.find((item) => item.id === route.params.colaborador)
    if (found === undefined) notFound.value = true
    else form.load(found)
  }
  loading.value = false
}

onMounted(load)

const title = computed(
  () =>
    ({
      create: 'Nuevo colaborador',
      own: 'Crear mi perfil de colaborador',
      edit: 'Editar colaborador',
    })[mode],
)
const intro = computed(
  () =>
    ({
      create: 'Se crea su cuenta con una contraseña temporal que verá una sola vez al guardar.',
      own: 'Usted sigue siendo dueño. Con este perfil tendrá agenda propia y podrá recibir reservas.',
      edit: 'Cambie los servicios que ofrece y su horario. El nombre y el correo no se cambian aquí.',
    })[mode],
)
const submitLabel = computed(
  () => ({ create: 'Crear colaborador', own: 'Crear perfil', edit: 'Guardar cambios' })[mode],
)

function shopHours(day: number): string {
  return schedule.status === 'ready'
    ? hoursLabel(schedule.slots.filter((slot) => slot.dia === day))
    : ''
}

const copied = ref(false)
async function copyPassword(): Promise<void> {
  if (form.password.value === null) return
  try {
    await navigator.clipboard.writeText(form.password.value)
    copied.value = true
  } catch {
    copied.value = false
  }
}

// Leaving the screen of a new account without having said the password was copied: it will not be shown again.
const exitOpen = ref(false)
let exitConfirmed = false
function askToLeave(): void {
  exitOpen.value = true
}
async function confirmExit(): Promise<void> {
  exitConfirmed = true
  exitOpen.value = false
  await router.push(membersLink.value)
}
onBeforeRouteLeave(() => {
  if (form.created.value === null || exitConfirmed) return true
  exitOpen.value = true
  return false
})
</script>

<template>
  <section aria-labelledby="member-title">
    <BackLink :to="membersLink">Volver al equipo</BackLink>
    <h2 id="member-title" class="mt-4 text-lg font-medium">{{ title }}</h2>

    <TemporaryPasswordResult
      v-if="form.created.value"
      class="mt-4"
      :account="form.created.value"
      :services-total="services.items.length"
      @done="askToLeave"
    />

    <template v-else>
      <p class="mt-1 text-sm text-fg-muted">{{ intro }}</p>

      <SkeletonBlock
        v-if="loading"
        class="mt-6"
        shape="row"
        :count="5"
        label="Cargando el formulario"
      />
      <ErrorState
        v-else-if="notFound"
        class="mt-6"
        message="No encontramos al colaborador"
        description="Puede que ya no exista. Vuelva a la lista e inténtelo de nuevo."
        retry-label="Volver a cargar"
        @retry="load"
      />
      <ErrorState
        v-else-if="services.status === 'error'"
        class="mt-6"
        message="No pudimos cargar los servicios"
        description="Revise su conexión e inténtelo de nuevo."
        @retry="load"
      />
      <EmptyState
        v-else-if="services.items.length === 0"
        title="Primero cree un servicio."
        description="Un colaborador se reserva solo para los servicios que ofrece, así que la barbería necesita al menos uno."
      >
        <template #action>
          <RouterLink
            :to="{ name: 'staff-services', params: { barberia } }"
            class="inline-flex min-h-11 items-center text-fg underline"
            >Ir a servicios</RouterLink
          >
        </template>
      </EmptyState>

      <form v-else class="mt-6 flex flex-col gap-8" novalidate @submit.prevent="form.submit()">
        <NoticeBanner v-if="form.finished.value">
          {{ mode === 'own' ? 'Perfil creado.' : 'Cambios guardados.' }}
        </NoticeBanner>
        <NoticeBanner v-if="form.generalError.value" variant="error">
          {{ form.generalError.value }}
          <RouterLink v-if="mode === 'own'" :to="membersLink" class="ml-1 text-fg underline"
            >Volver al equipo</RouterLink
          >
        </NoticeBanner>
        <!-- The account exists but a later step failed: say it and keep the password within reach. -->
        <NoticeBanner v-if="form.partial.value" variant="error" data-testid="partial-notice">
          <p>
            La cuenta de {{ form.name.value }} ya se creó, pero faltan datos por guardar. Corrija lo
            marcado y vuelva a guardar: no se creará otra cuenta.
          </p>
          <p class="mt-2">
            Contraseña temporal: <span class="font-mono">{{ form.password.value }}</span>
            <BaseButton variant="secondary" class="ml-2" @click="copyPassword">
              <template #icon><Copy :size="16" :stroke-width="1.5" aria-hidden="true" /></template>
              {{ copied ? 'Copiada' : 'Copiar contraseña' }}
            </BaseButton>
          </p>
        </NoticeBanner>

        <fieldset v-if="mode === 'create'" class="flex flex-col gap-4">
          <legend class="text-base font-medium">Datos de la cuenta</legend>
          <TextField
            v-model="form.name.value"
            label="Nombre completo"
            autocomplete="off"
            :disabled="form.collaboratorId.value !== null"
            :error="form.fieldErrors.name"
            @input="form.fieldErrors.name = ''"
          />
          <TextField
            v-model="form.email.value"
            type="email"
            label="Correo"
            autocomplete="off"
            :disabled="form.collaboratorId.value !== null"
            :error="form.fieldErrors.email"
            @input="form.fieldErrors.email = ''"
          />
        </fieldset>
        <div v-else-if="mode === 'edit'" class="text-sm">
          <p class="font-medium text-fg">{{ form.name.value }}</p>
          <p class="text-fg-muted">{{ form.email.value }}</p>
        </div>

        <fieldset class="flex flex-col gap-2" aria-describedby="services-help">
          <legend class="text-base font-medium">Servicios que ofrece</legend>
          <p id="services-help" class="text-sm text-fg-muted">
            Solo se reserva con esta persona lo que marque aquí.
          </p>
          <ul class="flex flex-col">
            <li v-for="service in services.items" :key="service.id" class="border-b border-line">
              <BaseCheckbox
                :model-value="form.selected.value.includes(service.id)"
                @update:model-value="
                  (checked: boolean) => {
                    form.fieldErrors.services = ''
                    form.selected.value = checked
                      ? [...form.selected.value, service.id]
                      : form.selected.value.filter((id) => id !== service.id)
                  }
                "
              >
                <span class="block font-medium text-fg">{{ service.nombre }}</span>
                <span class="block text-sm text-fg-muted tabular-nums"
                  >{{ durationLabel(service.duracion_minutos) }} ·
                  {{ colones(service.precio) }}</span
                >
              </BaseCheckbox>
            </li>
          </ul>
          <p v-if="form.fieldErrors.services" role="alert" class="text-sm text-danger">
            {{ form.fieldErrors.services }}
          </p>
        </fieldset>

        <div>
          <h3 class="text-base font-medium">Horario y pausas</h3>
          <p class="mt-1 text-sm text-fg-muted">
            Cada turno y cada pausa deben caer dentro del horario de la barbería. Horas de Costa
            Rica, en 24 horas.
          </p>
          <WorkDayEditor
            v-for="day in form.days"
            :key="day.day"
            :day="day"
            :form="form"
            :shop-hours="shopHours(day.day)"
          />
        </div>

        <div class="flex flex-wrap items-center gap-3">
          <BaseButton type="submit" size="large" :loading="form.saving.value">{{
            submitLabel
          }}</BaseButton>
          <RouterLink
            :to="membersLink"
            class="inline-flex min-h-12 items-center rounded-pill border border-line-strong px-5 text-sm text-fg no-underline transition-colors duration-150 ease-out hover:border-line-hover"
            >{{ form.finished.value ? 'Volver al equipo' : 'Cancelar' }}</RouterLink
          >
        </div>
      </form>
    </template>

    <ConfirmDialog
      v-model="exitOpen"
      title="¿Ya copió la contraseña?"
      description="Al salir de esta pantalla no se vuelve a mostrar."
      cancel-label="No, volver"
      confirm-label="Sí, salir"
      @confirm="confirmExit"
    />
  </section>
</template>
