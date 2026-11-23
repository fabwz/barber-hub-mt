<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { Plus } from 'lucide-vue-next'

import { isApiError } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import InlineNotice from '@/components/InlineNotice.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import StatusLabel from '@/components/StatusLabel.vue'
import { dateOf, formatShortDate, timeOf } from '@/composables/format'
import { shopInitials } from '@/composables/initials'
import { useServiceAdminStore } from '@/stores/services'
import { useTeamStore, type Collaborator } from '@/stores/team'

// «Colaboradores» (RF-05; lámina 33): the team with its status and the services each one offers, the profile of the
// owner (CO-02) and deactivating (CO-03). Reactivating asks nothing; deactivating asks first, and if the API refuses
// because of confirmed reservations ahead it shows which ones, to resolve them from the agenda.
const route = useRoute()
const team = useTeamStore()
const services = useServiceAdminStore()

const barberia = computed(() => String(route.params.barberia))

onMounted(() => {
  void team.load(barberia.value)
  void services.load(barberia.value)
})

const ownerHasProfile = computed(() => team.items.some((item) => item.es_dueno))

/** «6 de 7 servicios»; with no list of services to compare, just how many it offers. */
function servicesLabel(collaborator: Collaborator): string {
  const offered = collaborator.servicios.length
  const word = (count: number) => (count === 1 ? 'servicio' : 'servicios')
  return services.status === 'ready'
    ? `${offered} de ${services.items.length} ${word(services.items.length)}`
    : `${offered} ${word(offered)}`
}

const asking = ref<Collaborator | null>(null)
const askOpen = ref(false)
const changing = ref<string | null>(null)
const done = ref('')
const failed = ref<{ collaborator: Collaborator; active: boolean } | null>(null)

interface BlockingReservation {
  id: string
  start: string
  service: string
  customer: string
}
const blocked = ref<{
  collaborator: Collaborator
  list: BlockingReservation[]
  total: number
} | null>(null)
const blockedOpen = ref(false)

function readReservations(list: unknown[]): BlockingReservation[] {
  return list.flatMap((entry) => {
    if (typeof entry !== 'object' || entry === null) return []
    const { id, inicio, servicio, cliente } = entry as Record<string, unknown>
    return typeof id === 'string' && typeof inicio === 'string'
      ? [
          {
            id,
            start: inicio,
            service: typeof servicio === 'string' ? servicio : '',
            customer: typeof cliente === 'string' ? cliente : '',
          },
        ]
      : []
  })
}

function askToDeactivate(collaborator: Collaborator): void {
  asking.value = collaborator
  askOpen.value = true
}

async function setActive(collaborator: Collaborator, active: boolean): Promise<void> {
  changing.value = collaborator.id
  done.value = ''
  failed.value = null
  try {
    await team.setActive(barberia.value, collaborator.id, active)
    done.value = active
      ? `${collaborator.nombre} quedó activo y ya puede recibir reservas.`
      : `${collaborator.nombre} quedó inactivo y ya no recibe reservas.`
  } catch (error) {
    if (isApiError(error) && error.status === 401) return
    if (isApiError(error) && error.status === 409 && !active) {
      // CO-03: confirmed reservations ahead. The agenda is where they are reprogrammed or cancelled.
      const list = readReservations(error.reservations)
      blocked.value = {
        collaborator,
        list,
        total: Math.max(error.reservationsTotal, list.length),
      }
      blockedOpen.value = true
    } else {
      failed.value = { collaborator, active }
    }
  } finally {
    changing.value = null
    askOpen.value = false
    asking.value = null
  }
}

function confirmDeactivation(): void {
  if (asking.value !== null) void setActive(asking.value, false)
}

function retry(): void {
  if (failed.value !== null) void setActive(failed.value.collaborator, failed.value.active)
}

function startLabel(reservation: BlockingReservation): string {
  return `${formatShortDate(dateOf(reservation.start))}, ${timeOf(reservation.start)}`
}

const NEW_LINK_CLASS =
  'inline-flex min-h-11 items-center gap-2 rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover'
const EDIT_LINK_CLASS =
  'inline-flex min-h-11 items-center rounded-pill border border-line-strong px-5 text-sm text-fg no-underline transition-colors duration-150 ease-out hover:border-line-hover'
</script>

<template>
  <section aria-labelledby="members-title" :aria-busy="team.status === 'loading'">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h2 id="members-title" class="text-lg font-medium">Colaboradores</h2>
        <p class="mt-1 text-sm text-fg-muted">
          Quién atiende, qué servicios ofrece y si recibe reservas.
        </p>
      </div>
      <RouterLink
        :to="{ name: 'staff-team-member-new', params: { barberia } }"
        :class="NEW_LINK_CLASS"
      >
        <Plus :size="16" :stroke-width="1.5" aria-hidden="true" />
        Nuevo colaborador
      </RouterLink>
    </div>

    <div class="mt-6 flex flex-col gap-4">
      <NoticeBanner v-if="done">{{ done }}</NoticeBanner>
      <ErrorState
        v-if="failed"
        :message="`No pudimos cambiar el estado de ${failed.collaborator.nombre}`"
        description="Inténtelo de nuevo."
        @retry="retry"
      />

      <SkeletonBlock
        v-if="team.status === 'idle' || (team.status === 'loading' && team.items.length === 0)"
        shape="row"
        :count="4"
        label="Cargando los colaboradores"
      />
      <ErrorState
        v-else-if="team.status === 'error'"
        message="No pudimos cargar los colaboradores"
        description="Revise su conexión e inténtelo de nuevo."
        @retry="team.load(barberia)"
      />
      <template v-else>
        <!-- CO-02: the owner may have a collaborator profile and stays the owner. -->
        <InlineNotice v-if="!ownerHasProfile" title="Usted todavía no tiene perfil de colaborador.">
          Cree su perfil para recibir reservas en su propia agenda; seguirá siendo dueño.
          <RouterLink
            :to="{ name: 'staff-team-own-profile', params: { barberia } }"
            class="mt-2 inline-flex min-h-11 items-center text-fg underline"
            >Crear mi perfil de colaborador</RouterLink
          >
        </InlineNotice>

        <EmptyState
          v-if="team.items.length === 0"
          title="Aún no tiene colaboradores."
          description="Cree el primero, con los servicios que ofrece y su horario, para repartir la agenda."
        />
        <ul v-else class="flex flex-col" aria-label="Colaboradores de la barbería">
          <li
            v-for="collaborator in team.items"
            :key="collaborator.id"
            class="flex flex-wrap items-center gap-x-4 gap-y-3 border-b border-line py-3"
            :data-testid="`collaborator-${collaborator.id}`"
          >
            <div
              class="grid size-12 flex-none place-items-center rounded-full border border-line bg-elevated text-sm font-medium"
              aria-hidden="true"
            >
              {{ shopInitials(collaborator.nombre) }}
            </div>
            <div class="min-w-0 flex-1 basis-48">
              <p class="font-medium text-fg">
                {{ collaborator.nombre }}<span v-if="collaborator.es_dueno"> · Dueño</span>
              </p>
              <p class="truncate text-sm text-fg-muted">{{ collaborator.correo }}</p>
              <p class="text-sm text-fg-muted tabular-nums">{{ servicesLabel(collaborator) }}</p>
            </div>
            <StatusLabel :status="collaborator.activo ? 'service_active' : 'service_inactive'" />
            <div class="flex flex-wrap items-center gap-2">
              <RouterLink
                :to="{
                  name: 'staff-team-member-edit',
                  params: { barberia, colaborador: collaborator.id },
                }"
                :aria-label="`Editar a ${collaborator.nombre}`"
                :class="EDIT_LINK_CLASS"
                >Editar</RouterLink
              >
              <BaseButton
                v-if="collaborator.activo"
                variant="secondary"
                :loading="changing === collaborator.id"
                :disabled="changing !== null"
                :aria-label="`Desactivar a ${collaborator.nombre}`"
                @click="askToDeactivate(collaborator)"
                >Desactivar</BaseButton
              >
              <BaseButton
                v-else
                variant="secondary"
                :loading="changing === collaborator.id"
                :disabled="changing !== null"
                :aria-label="`Activar a ${collaborator.nombre}`"
                @click="setActive(collaborator, true)"
                >Activar</BaseButton
              >
            </div>
          </li>
        </ul>
      </template>
    </div>

    <ConfirmDialog
      v-model="askOpen"
      :title="`¿Desactivar a ${asking?.nombre ?? ''}?`"
      :description="
        asking?.es_dueno
          ? 'Dejará de ofrecerse para reservar. Usted seguirá siendo dueño y podrá reactivar este perfil cuando quiera.'
          : 'Dejará de ofrecerse para reservar y de ingresar a BarberHub. Podrá reactivar este perfil cuando quiera.'
      "
      cancel-label="No, conservar"
      confirm-label="Sí, desactivar"
      :loading="changing !== null"
      @confirm="confirmDeactivation"
    />

    <!-- CO-03 (láminas 33e y 33f): nothing was changed; these are the reservations to resolve first. -->
    <ConfirmDialog
      v-model="blockedOpen"
      :title="`No se puede desactivar a ${blocked?.collaborator.nombre ?? ''}`"
      :description="`Tiene ${blocked?.total ?? 0} ${blocked?.total === 1 ? 'reserva futura confirmada' : 'reservas futuras confirmadas'}. Reprograme o cancele cada una desde la agenda y vuelva a intentarlo.`"
      cancel-label="Entendido"
    >
      <ul
        v-if="blocked && blocked.list.length > 0"
        class="mt-4 flex flex-col"
        aria-label="Reservas por resolver"
      >
        <li
          v-for="reservation in blocked.list"
          :key="reservation.id"
          class="flex items-center justify-between gap-3 border-b border-line py-2 text-sm"
        >
          <div class="min-w-0">
            <p class="font-medium text-fg tabular-nums">
              {{ startLabel(reservation) }} · {{ reservation.customer }}
            </p>
            <p class="text-fg-muted">{{ reservation.service }}</p>
          </div>
          <RouterLink
            :to="{
              name: 'staff-reservation',
              params: { barberia, reserva: reservation.id },
              query: { dia: dateOf(reservation.start) },
            }"
            :aria-label="`Abrir la reserva de ${reservation.customer} del ${startLabel(reservation)}`"
            class="inline-flex min-h-11 items-center text-fg underline"
            >Abrir</RouterLink
          >
        </li>
      </ul>
      <p v-if="blocked && blocked.total > blocked.list.length" class="mt-2 text-sm text-fg-muted">
        y {{ blocked.total - blocked.list.length }} más.
      </p>
    </ConfirmDialog>
  </section>
</template>
