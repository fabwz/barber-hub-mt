<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { ImageIcon, Plus } from 'lucide-vue-next'

import { isApiError } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import StaffNavbar from '@/components/StaffNavbar.vue'
import StatusLabel from '@/components/StatusLabel.vue'
import { colones, durationLabel } from '@/composables/format'
import { useServiceAdminStore, type AdminService } from '@/stores/services'

// «Servicios» of the owner (RF-03, lámina 31): the list with image, duration, price with VAT and status, and
// activate or deactivate (SV-04). Inactivating asks first; activating does not. A service is never deleted.
const route = useRoute()
const store = useServiceAdminStore()

const shopId = computed(() => String(route.params.barberia))
const newLink = computed(() => ({ name: 'staff-service-new', params: { barberia: shopId.value } }))

onMounted(() => store.load(shopId.value))

const confirming = ref<AdminService | null>(null)
const confirmOpen = ref(false)
/** The service whose status is changing right now. */
const changing = ref<string | null>(null)
const done = ref('')
/** The change that failed, to try it again as it was. */
const failed = ref<{ service: AdminService; active: boolean } | null>(null)

function askToDeactivate(service: AdminService): void {
  confirming.value = service
  confirmOpen.value = true
}

async function setActive(service: AdminService, active: boolean): Promise<void> {
  changing.value = service.id
  done.value = ''
  failed.value = null
  try {
    await store.update(shopId.value, service.id, { activo: active })
    done.value = active
      ? `«${service.nombre}» quedó activo. Ya se puede reservar.`
      : `«${service.nombre}» quedó inactivo. Ya no se puede reservar; sigue en el historial.`
  } catch (error) {
    // A 401 already closed the session; anything else is shown with a way to try again.
    if (!(isApiError(error) && error.status === 401)) failed.value = { service, active }
  } finally {
    changing.value = null
    confirmOpen.value = false
    confirming.value = null
  }
}

function confirmDeactivation(): void {
  if (confirming.value !== null) void setActive(confirming.value, false)
}

function retry(): void {
  if (failed.value !== null) void setActive(failed.value.service, failed.value.active)
}

const NEW_LINK_CLASS =
  'inline-flex min-h-11 items-center gap-2 rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover'
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <StaffNavbar />
    <main class="mx-auto w-full max-w-5xl flex-1 px-5 py-6 md:px-8">
      <section aria-labelledby="services-title" :aria-busy="store.status === 'loading'">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 id="services-title" class="text-2xl">Servicios</h1>
            <p class="mt-1 text-sm text-fg-muted">
              Lo que su barbería ofrece. Los precios incluyen el IVA de 13 %.
            </p>
          </div>
          <RouterLink :to="newLink" :class="NEW_LINK_CLASS">
            <Plus :size="16" :stroke-width="1.5" aria-hidden="true" />
            Nuevo servicio
          </RouterLink>
        </div>

        <div class="mt-6 flex flex-col gap-4">
          <NoticeBanner v-if="done">{{ done }}</NoticeBanner>
          <ErrorState
            v-if="failed"
            :message="`No pudimos cambiar el estado de «${failed.service.nombre}»`"
            description="Inténtelo de nuevo."
            @retry="retry"
          />

          <SkeletonBlock
            v-if="
              store.status === 'idle' || (store.status === 'loading' && store.items.length === 0)
            "
            shape="row"
            :count="5"
            label="Cargando los servicios"
          />
          <ErrorState
            v-else-if="store.status === 'error'"
            message="No pudimos cargar los servicios"
            description="Revise su conexión e inténtelo de nuevo."
            @retry="store.load(shopId)"
          />
          <EmptyState
            v-else-if="store.items.length === 0"
            title="Aún no tiene servicios."
            description="Cree el primero para que sus clientes puedan reservar. Cada servicio lleva nombre, duración y precio; la imagen es opcional."
          >
            <template #action>
              <RouterLink :to="newLink" :class="NEW_LINK_CLASS">
                <Plus :size="16" :stroke-width="1.5" aria-hidden="true" />
                Crear mi primer servicio
              </RouterLink>
            </template>
          </EmptyState>

          <ul v-else class="flex flex-col" aria-label="Servicios de la barbería">
            <li
              v-for="service in store.items"
              :key="service.id"
              class="flex flex-wrap items-center gap-x-4 gap-y-3 border-b border-line py-3"
              :data-testid="`service-${service.id}`"
            >
              <div
                class="grid size-14 flex-none place-items-center overflow-hidden rounded-field border border-line bg-surface text-fg-muted"
              >
                <img
                  v-if="service.imagen"
                  :src="service.imagen"
                  :alt="`Imagen de ${service.nombre}`"
                  class="size-full object-cover"
                />
                <ImageIcon v-else :size="20" :stroke-width="1.5" aria-hidden="true" />
              </div>
              <div class="min-w-0 flex-1 basis-48">
                <p class="font-medium text-fg">{{ service.nombre }}</p>
                <p class="text-sm text-fg-muted tabular-nums">
                  {{ durationLabel(service.duracion_minutos) }} · {{ colones(service.precio) }} IVA
                  incluido
                </p>
              </div>
              <StatusLabel :status="service.activo ? 'service_active' : 'service_inactive'" />
              <div class="flex flex-wrap items-center gap-2">
                <RouterLink
                  :to="{
                    name: 'staff-service-edit',
                    params: { barberia: shopId, servicio: service.id },
                  }"
                  :aria-label="`Editar ${service.nombre}`"
                  class="inline-flex min-h-11 items-center rounded-pill border border-line-strong px-5 text-sm text-fg no-underline transition-colors duration-150 ease-out hover:border-line-hover"
                >
                  Editar
                </RouterLink>
                <BaseButton
                  v-if="service.activo"
                  variant="secondary"
                  :loading="changing === service.id"
                  :disabled="changing !== null"
                  :aria-label="`Inactivar ${service.nombre}`"
                  @click="askToDeactivate(service)"
                  >Inactivar</BaseButton
                >
                <BaseButton
                  v-else
                  variant="secondary"
                  :loading="changing === service.id"
                  :disabled="changing !== null"
                  :aria-label="`Activar ${service.nombre}`"
                  @click="setActive(service, true)"
                  >Activar</BaseButton
                >
              </div>
            </li>
          </ul>
        </div>
      </section>
    </main>

    <ConfirmDialog
      v-model="confirmOpen"
      :title="`¿Inactivar «${confirming?.nombre ?? ''}»?`"
      description="Dejará de poder reservarse. Las reservas ya hechas y el historial no cambian."
      cancel-label="No, mantenerlo activo"
      confirm-label="Sí, inactivar"
      :loading="changing !== null"
      @confirm="confirmDeactivation"
    />
  </div>
</template>
