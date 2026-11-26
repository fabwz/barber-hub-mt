<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useTemplateRef } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { CircleAlert } from 'lucide-vue-next'

import { isApiError, type ApiError } from '@/api'
import BackLink from '@/components/BackLink.vue'
import BaseButton from '@/components/BaseButton.vue'
import BaseCheckbox from '@/components/BaseCheckbox.vue'
import ErrorState from '@/components/ErrorState.vue'
import InlineNotice from '@/components/InlineNotice.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import StaffNavbar from '@/components/StaffNavbar.vue'
import TextField from '@/components/TextField.vue'
import { restrict } from '@/composables/fieldInput'
import { colones, durationLabel } from '@/composables/format'
import { useFormErrors } from '@/composables/useFormErrors'
import { useServiceAdminStore } from '@/stores/services'
import {
  useVoucherDefinitionsStore,
  type VoucherDefinition,
  type VoucherDefinitionPatch,
} from '@/stores/voucherDefinitions'

// «Nuevo bono» and «Editar bono» (RF-26, BO-01; lámina 36f to 36l). One form for both. A voucher that already has
// sales keeps its sessions, validity and services as they were sold: only the name and the price change.
type Field = 'name' | 'sessions' | 'validity' | 'price' | 'services'
const FIELD_ORDER: readonly Field[] = ['name', 'sessions', 'validity', 'price', 'services']

const NAME_PROBLEM = 'Escriba el nombre del bono.'
const SESSIONS_PROBLEM = 'Las sesiones deben ser al menos 1.'
const VALIDITY_PROBLEM = 'La vigencia debe ser de al menos 1 día.'
const PRICE_PROBLEM = 'El precio debe ser mayor que ₡0, en colones enteros.'
const SERVICES_PROBLEM = 'Elija al menos un servicio cubierto.'
const MAX_NAME = 120

const route = useRoute()
const store = useVoucherDefinitionsStore()
const catalog = useServiceAdminStore()

const shopId = computed(() => String(route.params.barberia))
const vouchersLink = computed(() => ({
  name: 'staff-vouchers',
  params: { barberia: shopId.value },
}))
const servicesLink = computed(() => ({
  name: 'staff-services',
  params: { barberia: shopId.value },
}))
/** The voucher being edited, or the one just created. Null while a new one has not been saved. */
const voucherId = ref<string | null>(
  typeof route.params.bono === 'string' ? route.params.bono : null,
)
const editing = computed(() => voucherId.value !== null)

const name = ref('')
const sessions = ref('')
const validity = ref('')
const price = ref('')
const selected = ref<string[]>([])

/** What the API holds now, to send only what changed. */
const saved = ref<VoucherDefinition | null>(null)

const loading = ref(false)
const notFound = ref(false)
const saving = ref(false)
const done = ref('')

/** The terms of a sold voucher are locked (BO-01); the API says it with `tiene_ventas`. */
const hasSales = computed(() => saved.value?.tiene_ventas === true)

const errors = useFormErrors<Field>({
  nombre: 'name',
  sesiones: 'sessions',
  vigencia_dias: 'validity',
  precio: 'price',
  servicios: 'services',
})

const fields = {
  name: useTemplateRef<InstanceType<typeof TextField>>('nameField'),
  sessions: useTemplateRef<InstanceType<typeof TextField>>('sessionsField'),
  validity: useTemplateRef<InstanceType<typeof TextField>>('validityField'),
  price: useTemplateRef<InstanceType<typeof TextField>>('priceField'),
}
const servicesGroup = useTemplateRef<HTMLElement>('servicesGroup')

/** The services to choose from: the active ones, and any inactive one the voucher already covers. */
const choices = computed(() =>
  catalog.items.filter((service) => service.activo || selected.value.includes(service.id)),
)

function fill(voucher: VoucherDefinition): void {
  saved.value = voucher
  name.value = voucher.nombre
  sessions.value = String(voucher.sesiones)
  validity.value = String(voucher.vigencia_dias)
  price.value = String(voucher.precio)
  selected.value = [...voucher.servicios]
}

async function loadVoucher(): Promise<void> {
  notFound.value = false
  loading.value = true
  // A direct visit or a reload has no list yet; the API has a call for one voucher, but the list is what the screens share.
  const needsCatalog = catalog.shopId !== shopId.value || catalog.items.length === 0
  const needsVouchers =
    voucherId.value !== null && (store.shopId !== shopId.value || store.items.length === 0)
  await Promise.all([
    needsCatalog ? catalog.load(shopId.value) : undefined,
    needsVouchers ? store.load(shopId.value) : undefined,
  ])
  loading.value = false
  if (voucherId.value === null) return
  const found = store.items.find((item) => item.id === voucherId.value)
  if (found === undefined) notFound.value = true
  else fill(found)
}

onMounted(loadVoucher)

function digitsOnly(value: string): string {
  return value.replace(/\D/g, '')
}

function onNumber(event: Event, field: 'sessions' | 'validity' | 'price'): void {
  restrict(event, digitsOnly, { sessions, validity, price }[field])
  errors.clearField(field)
}

function toggleService(id: string, on: boolean): void {
  selected.value = on ? [...selected.value, id] : selected.value.filter((item) => item !== id)
  errors.clearField('services')
}

/** The quick check before the trip; the API still has the last word. */
function check(): boolean {
  errors.clear()
  if (name.value.trim() === '') errors.setField('name', NAME_PROBLEM)
  if (!hasSales.value) {
    if (sessions.value === '' || Number(sessions.value) < 1) {
      errors.setField('sessions', SESSIONS_PROBLEM)
    }
    if (validity.value === '' || Number(validity.value) < 1) {
      errors.setField('validity', VALIDITY_PROBLEM)
    }
    if (selected.value.length === 0) errors.setField('services', SERVICES_PROBLEM)
  }
  if (price.value === '' || Number(price.value) < 1) errors.setField('price', PRICE_PROBLEM)
  return Object.keys(errors.fieldErrors.value).length === 0
}

async function focusFirstError(): Promise<void> {
  await nextTick()
  const first = errors.firstField(FIELD_ORDER)
  if (first === undefined) return
  if (first === 'services') servicesGroup.value?.querySelector<HTMLElement>('button')?.focus()
  else fields[first].value?.focus()
}

/** Only the fields that differ from what the API holds; a sold voucher never sends its locked terms. */
function changes(): VoucherDefinitionPatch {
  const current = saved.value
  const patch: VoucherDefinitionPatch = {}
  if (current === null || name.value.trim() !== current.nombre) patch.nombre = name.value.trim()
  if (current === null || Number(price.value) !== current.precio) patch.precio = Number(price.value)
  if (hasSales.value) return patch
  if (current === null || Number(sessions.value) !== current.sesiones) {
    patch.sesiones = Number(sessions.value)
  }
  if (current === null || Number(validity.value) !== current.vigencia_dias) {
    patch.vigencia_dias = Number(validity.value)
  }
  const same =
    current !== null &&
    current.servicios.length === selected.value.length &&
    current.servicios.every((id) => selected.value.includes(id))
  if (!same) patch.servicios = [...selected.value]
  return patch
}

/** The 422 of the API in its field; `servicios.N` (a service that is not of this shop) goes to the group. */
function showApiError(error: ApiError): void {
  errors.setFromApiError(error)
  if (error.status !== 422) return
  const nested = Object.entries(error.errors)
    .filter(([key]) => key.startsWith('servicios.'))
    .flatMap(([, messages]) => messages)
  if (nested.length === 0) return
  const own = errors.fieldErrors.value.services
  errors.setField('services', [...(own === undefined ? [] : [own]), ...new Set(nested)].join(' '))
  errors.generalError.value = ''
}

async function submit(): Promise<void> {
  if (saving.value) return
  done.value = ''
  if (!check()) {
    await focusFirstError()
    return
  }
  saving.value = true
  try {
    let result: VoucherDefinition
    if (voucherId.value === null) {
      result = await store.create(shopId.value, {
        nombre: name.value.trim(),
        sesiones: Number(sessions.value),
        precio: Number(price.value),
        vigencia_dias: Number(validity.value),
        servicios: [...selected.value],
      })
      voucherId.value = result.id
    } else {
      const patch = changes()
      result =
        Object.keys(patch).length > 0
          ? await store.update(shopId.value, voucherId.value, patch)
          : (saved.value as VoucherDefinition)
    }
    const wasEditing = saved.value !== null
    fill(result)
    done.value = wasEditing
      ? `Bono guardado. El cambio aplica a bonos nuevos; los ya comprados no cambian.${result.activo ? ' Ya está a la venta.' : ''}`
      : 'Bono guardado. Ya está a la venta.'
  } catch (error) {
    if (isApiError(error)) {
      showApiError(error)
      // Someone bought it since the screen loaded: the terms are locked now, and the form says so.
      if (error.status === 409 && voucherId.value !== null) {
        await store.load(shopId.value)
        saved.value = store.items.find((item) => item.id === voucherId.value) ?? saved.value
      }
    }
    await focusFirstError()
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <StaffNavbar />
    <main class="mx-auto w-full max-w-2xl flex-1 px-5 py-6 md:px-8">
      <BackLink :to="vouchersLink">Volver a bonos</BackLink>
      <h1 id="voucher-title" class="mt-4 text-2xl">
        {{ editing ? 'Editar bono' : 'Nuevo bono' }}
      </h1>

      <SkeletonBlock v-if="loading" shape="row" :count="4" label="Cargando el bono" class="mt-6" />
      <ErrorState
        v-else-if="notFound"
        class="mt-6"
        message="No encontramos el bono"
        description="Puede que ya no exista. Vuelva a la lista e inténtelo de nuevo."
        retry-label="Volver a cargar"
        @retry="loadVoucher"
      />
      <ErrorState
        v-else-if="catalog.status === 'error'"
        class="mt-6"
        message="No pudimos cargar los servicios"
        description="Revise su conexión e inténtelo de nuevo."
        @retry="loadVoucher"
      />

      <form
        v-else
        class="mt-6 flex flex-col gap-6"
        novalidate
        aria-labelledby="voucher-title"
        @submit.prevent="submit"
      >
        <NoticeBanner v-if="done">{{ done }}</NoticeBanner>
        <NoticeBanner v-if="errors.generalError.value" variant="error">{{
          errors.generalError.value
        }}</NoticeBanner>
        <InlineNotice
          v-if="hasSales"
          title="Este bono ya tiene ventas: solo puede cambiar el nombre y el precio."
        >
          Las sesiones, la vigencia y los servicios cubiertos ya no se pueden modificar.
        </InlineNotice>

        <TextField
          ref="nameField"
          v-model="name"
          label="Nombre del bono"
          autocomplete="off"
          :maxlength="MAX_NAME"
          :error="errors.fieldErrors.value.name"
          @input="errors.clearField('name')"
        />

        <div class="flex flex-col gap-2">
          <TextField
            ref="sessionsField"
            v-model="sessions"
            label="Sesiones"
            inputmode="numeric"
            autocomplete="off"
            aria-describedby="sessions-help"
            :disabled="hasSales"
            :error="errors.fieldErrors.value.sessions"
            @input="onNumber($event, 'sessions')"
          />
          <p id="sessions-help" class="text-sm text-fg-muted">Cuántas visitas incluye.</p>
        </div>

        <div class="flex flex-col gap-2">
          <TextField
            ref="validityField"
            v-model="validity"
            label="Vigencia (días)"
            inputmode="numeric"
            autocomplete="off"
            aria-describedby="validity-help"
            :disabled="hasSales"
            :error="errors.fieldErrors.value.validity"
            @input="onNumber($event, 'validity')"
          />
          <p id="validity-help" class="text-sm text-fg-muted">Cuenta desde que el cliente paga.</p>
        </div>

        <div class="flex flex-col gap-2">
          <TextField
            ref="priceField"
            v-model="price"
            label="Precio (₡, IVA incluido)"
            inputmode="numeric"
            autocomplete="off"
            aria-describedby="price-help"
            :error="errors.fieldErrors.value.price"
            @input="onNumber($event, 'price')"
          />
          <p id="price-help" class="text-sm text-fg-muted">
            En colones enteros.
            <template v-if="editing">
              Cambiar el precio no cambia los bonos ya comprados.
            </template>
          </p>
        </div>

        <fieldset ref="servicesGroup" class="flex flex-col gap-1" aria-describedby="services-help">
          <legend class="text-sm font-medium text-fg">Servicios cubiertos</legend>
          <p id="services-help" class="text-sm text-fg-muted">
            El bono se puede usar solo en los servicios que elija.
          </p>
          <SkeletonBlock
            v-if="catalog.status === 'loading' && choices.length === 0"
            shape="row"
            :count="3"
            label="Cargando los servicios"
          />
          <p v-else-if="choices.length === 0" class="text-sm text-fg-muted">
            Aún no tiene servicios activos.
            <RouterLink
              :to="servicesLink"
              class="inline-flex min-h-11 items-center text-fg underline"
              >Cree un servicio</RouterLink
            >
            para armar un bono.
          </p>
          <template v-else>
            <BaseCheckbox
              v-for="service in choices"
              :key="service.id"
              :model-value="selected.includes(service.id)"
              :disabled="hasSales"
              @update:model-value="toggleService(service.id, $event)"
            >
              {{ service.nombre }}
              <span class="block text-fg-muted tabular-nums">
                {{ durationLabel(service.duracion_minutos) }} · {{ colones(service.precio) }}
              </span>
            </BaseCheckbox>
          </template>
          <p
            v-if="errors.fieldErrors.value.services"
            class="flex items-start gap-2 text-sm text-danger"
            data-testid="services-error"
          >
            <CircleAlert
              :size="16"
              :stroke-width="1.5"
              class="mt-0.5 shrink-0"
              aria-hidden="true"
            />
            <span>{{ errors.fieldErrors.value.services }}</span>
          </p>
        </fieldset>

        <div class="flex flex-wrap items-center gap-3">
          <BaseButton type="submit" size="large" :disabled="saving">{{
            saving ? 'Guardando…' : 'Guardar bono'
          }}</BaseButton>
          <RouterLink
            :to="vouchersLink"
            class="inline-flex min-h-12 items-center rounded-pill border border-line-strong px-5 text-sm text-fg no-underline transition-colors duration-150 ease-out hover:border-line-hover"
          >
            {{ done ? 'Volver a la lista' : 'Cancelar' }}
          </RouterLink>
        </div>
      </form>
    </main>
  </div>
</template>
