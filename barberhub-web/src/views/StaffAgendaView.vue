<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue'
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router'
import { ChevronLeft, ChevronRight, Plus } from 'lucide-vue-next'

import AgendaRow from '@/components/AgendaRow.vue'
import BaseButton from '@/components/BaseButton.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LiveIndicator from '@/components/LiveIndicator.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import StaffNavbar from '@/components/StaffNavbar.vue'
import { agendaContextKey } from '@/composables/agendaContext'
import {
  addDays,
  formatDateWithYear,
  formatShortDate,
  timeInCostaRica,
  todayInCostaRica,
} from '@/composables/format'
import { useAgendaPermissions } from '@/composables/useAgendaPermissions'
import { useLiveAgenda } from '@/composables/useLiveAgenda'
import {
  STATUS_FILTERS,
  currentTimeIndex,
  filterAgenda,
  isStatusFilter,
  useStaffAgenda,
} from '@/composables/useStaffAgenda'
import { useActiveShopStore } from '@/stores/activeShop'

// Agenda of the day (RF-13) for the owner and the collaborator, with the detail panel as a child route
// (RF-14). The day and the filters live in the URL, so a link or a reload brings the same view back.
// The live updates (RF-36, #113) read the day again when an event touches it; the indicator tells the state of the connection.
const route = useRoute()
const router = useRouter()
const shops = useActiveShopStore()
const agenda = useStaffAgenda()

const shop = computed(() => shops.active)
const permissions = useAgendaPermissions(() => shop.value?.role ?? 'colaborador')

const DAY_PATTERN = /^\d{4}-\d{2}-\d{2}$/

function queryText(name: string): string | null {
  const value = route.query[name]
  return typeof value === 'string' && value !== '' ? value : null
}

const today = computed(() => {
  // Read `now` so the day «today» rolls over at midnight while the screen stays open.
  void now.value
  return todayInCostaRica()
})
const day = computed(() => {
  const value = queryText('dia')
  return value !== null && DAY_PATTERN.test(value) ? value : today.value
})
const statusFilter = computed(() => {
  const value = queryText('estado')
  return isStatusFilter(value) ? value : null
})
// A collaborator that has nothing that day is not an option, so the filter would show nothing: ignored.
const collaboratorFilter = computed(() => {
  const value = queryText('colaborador')
  if (!permissions.value.canFilterByCollaborator || value === null) return null
  return agenda.options.value.some((option) => option.id === value) ? value : null
})
const hasFilters = computed(() => statusFilter.value !== null || collaboratorFilter.value !== null)

const visible = computed(() =>
  filterAgenda(agenda.items.value, {
    status: statusFilter.value,
    collaborator: collaboratorFilter.value,
  }),
)
const shownInDay = computed(() =>
  filterAgenda(agenda.items.value, { status: null, collaborator: null }),
)

const openId = computed(() =>
  typeof route.params.reserva === 'string' ? route.params.reserva : null,
)
const panelOpen = computed(() => openId.value !== null)

// The current time marks the list on today's date only.
const now = ref(Date.now())
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  timer = setInterval(() => (now.value = Date.now()), 30_000)
})
onBeforeUnmount(() => clearInterval(timer))

const markerIndex = computed(() =>
  day.value === today.value ? currentTimeIndex(visible.value, now.value) : -1,
)
const clock = computed(() => timeInCostaRica(new Date(now.value)))

const dayLabel = computed(() => formatDateWithYear(day.value))
const countLabel = computed(() => {
  const total = visible.value.length
  return total === 0 ? 'sin reservas' : `${total} ${total === 1 ? 'reserva' : 'reservas'}`
})

function load(quiet = false): Promise<void> {
  return shop.value === null ? Promise.resolve() : agenda.load(shop.value.id, day.value, quiet)
}

watch([() => shop.value?.id, day], () => void load(), { immediate: true })

const live = useLiveAgenda({
  shop: () => shop.value,
  day: () => day.value,
  refresh: () => load(true),
})

provide(agendaContextKey, { reload: () => load(true), changes: live.changes })

/** Moves to another day or filter keeping the URL as the single source; another day closes the panel. */
function go(changes: Record<string, string | null>, closePanel = false): void {
  const query: LocationQueryRaw = { ...route.query }
  for (const [name, value] of Object.entries(changes)) {
    if (value === null) delete query[name]
    else query[name] = value
  }
  if (closePanel && shop.value) {
    void router.push({ name: 'staff-agenda', params: { barberia: shop.value.id }, query })
  } else {
    void router.replace({ query })
  }
}

function moveDay(days: number): void {
  go({ dia: addDays(day.value, days) }, true)
}

function goToday(): void {
  go({ dia: null }, true)
}

function clearFilters(): void {
  go({ estado: null, colaborador: null })
}

function onStatus(event: Event): void {
  go({ estado: (event.target as HTMLSelectElement).value || null })
}

function onCollaborator(event: Event): void {
  go({ colaborador: (event.target as HTMLSelectElement).value || null })
}

function rowTo(id: string) {
  return {
    name: 'staff-reservation',
    // The shop of the address, which stays while the screen is up even if the store is cleared (401, logout).
    params: { barberia: String(route.params.barberia ?? ''), reserva: id },
    query: route.query,
  }
}

const SELECT_CLASS =
  'min-h-12 w-full rounded-field border border-field bg-canvas px-3 text-base text-fg focus:border-accent focus:outline-none'
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <StaffNavbar />
    <main class="flex min-h-0 flex-1 flex-col md:flex-row">
      <section
        aria-labelledby="agenda-title"
        :aria-busy="agenda.status.value === 'loading'"
        class="min-w-0 flex-1 px-5 py-6 md:px-8"
        :class="panelOpen ? 'max-md:hidden' : ''"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 id="agenda-title" class="text-2xl">{{ permissions.title }}</h1>
            <p class="mt-1 text-sm text-fg-muted" aria-live="polite">
              {{ dayLabel
              }}<template v-if="agenda.status.value === 'ready'"> · {{ countLabel }}</template>
            </p>
          </div>
          <!-- RF-12: only the owner registers a reservation for a person without an account. -->
          <RouterLink
            v-if="permissions.canRegisterAssisted && shop"
            :to="{ name: 'staff-assisted-booking', params: { barberia: shop.id } }"
            class="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover"
          >
            <Plus :size="16" :stroke-width="1.5" aria-hidden="true" />
            Reserva asistida
          </RouterLink>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2">
          <LiveIndicator :state="live.state.value" />
          <div class="flex flex-wrap items-center gap-2">
            <BaseButton variant="secondary" @click="moveDay(-1)">
              <template #icon
                ><ChevronLeft :size="18" :stroke-width="1.5" aria-hidden="true"
              /></template>
              Anterior
            </BaseButton>
            <BaseButton variant="secondary" :disabled="day === today" @click="goToday"
              >Hoy</BaseButton
            >
            <BaseButton variant="secondary" @click="moveDay(1)">
              Siguiente
              <template #icon
                ><ChevronRight :size="18" :stroke-width="1.5" aria-hidden="true"
              /></template>
            </BaseButton>
          </div>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 md:max-w-xl">
          <div v-if="permissions.canFilterByCollaborator">
            <label for="agenda-collaborator" class="mb-2 block text-sm font-medium"
              >Colaborador</label
            >
            <select
              id="agenda-collaborator"
              :class="SELECT_CLASS"
              :value="collaboratorFilter ?? ''"
              @change="onCollaborator"
            >
              <option value="">Todos</option>
              <option v-for="option in agenda.options.value" :key="option.id" :value="option.id">
                {{ option.name }}
              </option>
            </select>
          </div>
          <div>
            <label for="agenda-status" class="mb-2 block text-sm font-medium">Estado</label>
            <select
              id="agenda-status"
              :class="SELECT_CLASS"
              :value="statusFilter ?? ''"
              @change="onStatus"
            >
              <option value="">Todos</option>
              <option v-for="filter in STATUS_FILTERS" :key="filter.value" :value="filter.value">
                {{ filter.label }}
              </option>
            </select>
          </div>
        </div>
        <BaseButton v-if="hasFilters" variant="secondary" class="mt-3" @click="clearFilters"
          >Limpiar filtros</BaseButton
        >

        <div class="mt-6">
          <SkeletonBlock
            v-if="agenda.status.value === 'loading'"
            shape="row"
            :count="5"
            label="Cargando la agenda"
          />

          <ErrorState
            v-else-if="agenda.status.value === 'error'"
            :message="`No pudimos cargar la agenda del ${formatShortDate(day)}.`"
            description="Revise la conexión a internet del local. Si el problema sigue, intente de nuevo en unos minutos."
            @retry="load()"
          />

          <div v-else-if="shownInDay.length === 0" class="border-y border-line">
            <EmptyState
              title="No hay reservas este día."
              description="Cuando un cliente reserve, aparecerá aquí."
            >
              <template v-if="day !== today" #action>
                <BaseButton variant="secondary" @click="goToday">Ir a hoy</BaseButton>
              </template>
            </EmptyState>
          </div>

          <div v-else-if="visible.length === 0" class="border-y border-line">
            <EmptyState
              title="No hay reservas con esos filtros."
              description="Pruebe con otro colaborador o estado."
            >
              <template #action>
                <BaseButton variant="secondary" @click="clearFilters">Limpiar filtros</BaseButton>
              </template>
            </EmptyState>
          </div>

          <ul v-else aria-label="Reservas del día" class="border-y border-line">
            <template v-for="(item, index) in visible" :key="item.id">
              <li
                v-if="markerIndex === index"
                class="flex items-center gap-2 px-3 py-1"
                data-testid="now-marker"
              >
                <span class="size-2.25 flex-none rounded-full bg-accent" aria-hidden="true" />
                <span class="text-sm text-fg-muted tabular-nums"
                  ><span class="sr-only">Hora actual </span>{{ clock }}</span
                >
                <span class="h-px flex-1 bg-accent" aria-hidden="true" />
              </li>
              <li class="border-b border-line last:border-b-0">
                <AgendaRow
                  :reservation="item"
                  :to="rowTo(item.id)"
                  :selected="item.id === openId"
                  :show-collaborator="permissions.canFilterByCollaborator"
                />
              </li>
            </template>
            <li
              v-if="markerIndex === visible.length"
              class="flex items-center gap-2 border-t border-line px-3 py-1"
              data-testid="now-marker"
            >
              <span class="size-2.25 flex-none rounded-full bg-accent" aria-hidden="true" />
              <span class="text-sm text-fg-muted tabular-nums"
                ><span class="sr-only">Hora actual </span>{{ clock }}</span
              >
              <span class="h-px flex-1 bg-accent" aria-hidden="true" />
            </li>
          </ul>
        </div>
      </section>

      <!-- Desktop: a 400 px column beside the agenda. Phone: the whole screen, with «Volver». -->
      <aside
        v-if="panelOpen"
        aria-label="Detalle de la reserva"
        class="w-full flex-none md:w-100 md:border-l md:border-line"
      >
        <RouterView />
      </aside>
    </main>
  </div>
</template>
