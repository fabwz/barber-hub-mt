<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useTemplateRef } from 'vue'
import { useRoute } from 'vue-router'

import { isApiError } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import ErrorState from '@/components/ErrorState.vue'
import InlineNotice from '@/components/InlineNotice.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import OptionCard from '@/components/OptionCard.vue'
import OptionGroup from '@/components/OptionGroup.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import SwitchField from '@/components/SwitchField.vue'
import TextField from '@/components/TextField.vue'
import { restrict } from '@/composables/fieldInput'
import { partialMessage, runSteps, type SaveStep } from '@/composables/saveSteps'
import { useFormErrors } from '@/composables/useFormErrors'
import { useSettingsStore, type BillingPatch, type FeaturesPatch } from '@/stores/settings'

// «Configuración · Cobro y políticas» (RF-08, RF-09, RF-23; lámina 35). The policy and the two switches go with
// one button but two calls, in order. A change applies to the new bookings; the API keeps the old ones as they were.
type Field = 'percentage' | 'gateway' | 'deadline'
type Mode = NonNullable<BillingPatch['modalidad']>
type Gateway = 'onvo' | 'paypal'
const FIELD_ORDER: readonly Field[] = ['percentage', 'gateway', 'deadline']

const MODES: ReadonlyArray<{ value: Mode; label: string; description: string }> = [
  {
    value: 'sin_pago',
    label: 'Sin pago',
    description: 'La reserva se confirma sin cobro en línea.',
  },
  {
    value: 'deposito',
    label: 'Depósito',
    description: 'Se cobra un porcentaje al reservar; el saldo se paga en el local.',
  },
  { value: 'total', label: 'Pago total', description: 'Se cobra todo el precio al reservar.' },
]
const GATEWAYS: ReadonlyArray<{ value: Gateway; label: string; description: string }> = [
  { value: 'onvo', label: 'ONVO Pay', description: 'Tarjeta y SINPE Móvil.' },
  { value: 'paypal', label: 'PayPal', description: 'Tarjeta.' },
]
const PERCENTAGE_PROBLEM = 'El porcentaje debe estar entre 10 y 100 %.'
const DEADLINE_PROBLEM = 'El plazo debe estar entre 0 y 720 horas.'
const GATEWAY_PROBLEM = 'Elija una pasarela para cobrar.'

const route = useRoute()
const store = useSettingsStore()
const shopId = computed(() => String(route.params.barberia))

const mode = ref<Mode>('sin_pago')
const percentage = ref('')
const gateway = ref<Gateway | null>(null)
const deadline = ref('')
const vouchers = ref(false)
const waitlist = ref(false)

const saving = ref(false)
const done = ref(false)
const errors = useFormErrors<Field>({
  porcentaje_deposito: 'percentage',
  pasarela: 'gateway',
  plazo_cancelacion_horas: 'deadline',
})

const percentageField = useTemplateRef<InstanceType<typeof TextField>>('percentageField')
const deadlineField = useTemplateRef<InstanceType<typeof TextField>>('deadlineField')

const charges = computed(() => mode.value !== 'sin_pago')
// The vouchers are sold through the gateway: without one the clients could not buy (BO-01, PA-02).
const voucherNotice = computed(() => vouchers.value && gateway.value === null)

/** Puts what the API holds into the form. */
function fill(): void {
  const policy = store.billing
  if (policy === null) return
  mode.value = policy.modalidad as Mode
  percentage.value = policy.porcentaje_deposito === null ? '' : String(policy.porcentaje_deposito)
  gateway.value = policy.pasarela as Gateway | null
  deadline.value = String(policy.plazo_cancelacion_horas)
  vouchers.value = store.features?.bonos_activos ?? false
  waitlist.value = store.features?.lista_espera_activa ?? false
}

async function load(): Promise<void> {
  await store.loadBilling(shopId.value)
  if (store.billingStatus === 'ready') fill()
}
onMounted(load)

function onMode(value: string | null): void {
  if (value === null) return
  mode.value = value as Mode
  errors.clear()
  done.value = false
}

function onNumber(event: Event, field: 'percentage' | 'deadline'): void {
  restrict(
    event,
    (value) => value.replace(/\D/g, ''),
    field === 'percentage' ? percentage : deadline,
  )
  errors.clearField(field)
  done.value = false
}

/** The quick check before the trip; the API still has the last word. */
function check(): boolean {
  errors.clear()
  if (mode.value === 'deposito') {
    const value = Number(percentage.value)
    if (percentage.value === '' || value < 10 || value > 100) {
      errors.setField('percentage', PERCENTAGE_PROBLEM)
    }
  }
  if (charges.value && gateway.value === null) errors.setField('gateway', GATEWAY_PROBLEM)
  const hours = Number(deadline.value)
  if (deadline.value === '' || hours < 0 || hours > 720) {
    errors.setField('deadline', DEADLINE_PROBLEM)
  }
  return Object.keys(errors.fieldErrors.value).length === 0
}

async function focusFirstError(): Promise<void> {
  await nextTick()
  const first = errors.firstField(FIELD_ORDER)
  if (first === 'percentage') percentageField.value?.focus()
  else if (first === 'deadline') deadlineField.value?.focus()
  else if (first === 'gateway')
    document.querySelector<HTMLElement>('[data-gateway] [role="radio"]')?.focus()
}

/** Only what differs from what the API holds. With «Sin pago» no gateway travels: the old one stays. */
function billingPatch(): BillingPatch {
  const current = store.billing!
  const patch: BillingPatch = {}
  if (mode.value !== current.modalidad) patch.modalidad = mode.value
  if (
    mode.value === 'deposito' &&
    (mode.value !== current.modalidad || Number(percentage.value) !== current.porcentaje_deposito)
  ) {
    patch.porcentaje_deposito = Number(percentage.value)
  }
  if (Number(deadline.value) !== current.plazo_cancelacion_horas) {
    patch.plazo_cancelacion_horas = Number(deadline.value)
  }
  if (charges.value && gateway.value !== null && gateway.value !== current.pasarela) {
    patch.pasarela = gateway.value
  }
  return patch
}

function featuresPatch(): FeaturesPatch {
  const current = store.features!
  const patch: FeaturesPatch = {}
  if (vouchers.value !== current.bonos_activos) patch.bonos_activos = vouchers.value
  if (waitlist.value !== current.lista_espera_activa) patch.lista_espera_activa = waitlist.value
  return patch
}

function steps(): SaveStep[] {
  const list: SaveStep[] = []
  if (Object.keys(billingPatch()).length > 0) {
    list.push({
      label: 'la política de cobro',
      run: async () => {
        await store.updateBilling(shopId.value, billingPatch())
      },
    })
  }
  if (Object.keys(featuresPatch()).length > 0) {
    list.push({
      label: 'las funciones',
      run: async () => {
        await store.updateFeatures(shopId.value, featuresPatch())
      },
    })
  }
  return list
}

async function submit(): Promise<void> {
  if (saving.value) return
  done.value = false
  if (!check()) {
    await focusFirstError()
    return
  }
  saving.value = true
  const outcome = await runSteps(steps())
  saving.value = false
  if (outcome.failed === null) {
    fill()
    done.value = true
    return
  }
  const { error } = outcome.failed
  // A 401 closes the session in the client; there is nothing to tell here.
  if (isApiError(error) && error.status === 401) return
  if (isApiError(error)) errors.setFromApiError(error)
  const hasFieldErrors = Object.keys(errors.fieldErrors.value).length > 0
  if (outcome.saved.length > 0) {
    errors.generalError.value = partialMessage(outcome)
  } else if (isApiError(error) && error.status === 422) {
    // A rule between fields comes with no field, only its text: it goes in the general notice.
    if (!hasFieldErrors && errors.generalError.value === '') {
      errors.generalError.value = `No pudimos guardar los cambios. ${partialMessage(outcome)}`
    }
  } else {
    errors.generalError.value = `No pudimos guardar los cambios. ${partialMessage(outcome)}`
  }
  await focusFirstError()
}
</script>

<template>
  <section aria-labelledby="billing-title">
    <h2 id="billing-title" class="sr-only">Cobro y políticas</h2>
    <SkeletonBlock
      v-if="store.billingStatus === 'loading' || store.billingStatus === 'idle'"
      shape="row"
      :count="6"
      label="Cargando la configuración de cobro"
    />
    <ErrorState
      v-else-if="store.billingStatus === 'error'"
      message="No pudimos cargar la configuración de cobro"
      description="Revise su conexión e inténtelo de nuevo."
      @retry="load"
    />
    <form
      v-else
      class="flex flex-col gap-8"
      novalidate
      aria-labelledby="billing-title"
      @submit.prevent="submit"
    >
      <NoticeBanner v-if="done">Cambios guardados. Aplican a las reservas nuevas.</NoticeBanner>
      <NoticeBanner v-if="errors.generalError.value" variant="error">{{
        errors.generalError.value
      }}</NoticeBanner>

      <section class="flex flex-col gap-5" aria-labelledby="charge-title">
        <h3 id="charge-title" class="text-lg font-medium">Cobro de las reservas</h3>
        <InlineNotice title="Un cambio aplica a las reservas nuevas.">
          Las que ya están hechas conservan lo que tenían.
        </InlineNotice>

        <div class="flex flex-col gap-2">
          <p id="mode-label" class="text-sm font-medium text-fg">Modalidad</p>
          <OptionGroup
            :model-value="mode"
            aria-labelledby="mode-label"
            @update:model-value="onMode"
          >
            <OptionCard v-for="item in MODES" :key="item.value" :value="item.value">
              {{ item.label }}
              <template #description>{{ item.description }}</template>
            </OptionCard>
          </OptionGroup>
        </div>

        <div v-if="mode === 'deposito'" class="flex flex-col gap-2">
          <TextField
            ref="percentageField"
            v-model="percentage"
            label="Porcentaje del depósito (%)"
            inputmode="numeric"
            autocomplete="off"
            maxlength="3"
            aria-describedby="percentage-help"
            :error="errors.fieldErrors.value.percentage"
            @input="onNumber($event, 'percentage')"
          />
          <p id="percentage-help" class="text-sm text-fg-muted">De 10 a 100 %.</p>
        </div>

        <div v-if="charges" class="flex flex-col gap-2" data-gateway>
          <p id="gateway-label" class="text-sm font-medium text-fg">Pasarela de pago</p>
          <OptionGroup
            :model-value="gateway"
            aria-labelledby="gateway-label"
            @update:model-value="
              (value) => {
                gateway = value as Gateway
                errors.clearField('gateway')
                done = false
              }
            "
          >
            <OptionCard v-for="item in GATEWAYS" :key="item.value" :value="item.value">
              {{ item.label }}
              <template #description>{{ item.description }}</template>
            </OptionCard>
          </OptionGroup>
          <p v-if="errors.fieldErrors.value.gateway" class="text-sm text-danger">
            {{ errors.fieldErrors.value.gateway }}
          </p>
        </div>
        <p v-else class="text-sm text-fg-muted">
          Sin pago no hay pasarela: la reserva se confirma sin cobro.
        </p>
      </section>

      <section class="flex flex-col gap-5" aria-labelledby="cancel-title">
        <h3 id="cancel-title" class="text-lg font-medium">Cancelación gratuita</h3>
        <div class="flex flex-col gap-2">
          <TextField
            ref="deadlineField"
            v-model="deadline"
            label="Plazo, en horas antes de la reserva"
            inputmode="numeric"
            autocomplete="off"
            maxlength="3"
            aria-describedby="deadline-help"
            :error="errors.fieldErrors.value.deadline"
            @input="onNumber($event, 'deadline')"
          />
          <p id="deadline-help" class="text-sm text-fg-muted">De 0 a 720 horas.</p>
        </div>
      </section>

      <section class="flex flex-col gap-5" aria-labelledby="features-title">
        <h3 id="features-title" class="text-lg font-medium">Funciones</h3>
        <p class="-mt-3 text-sm text-fg-muted">
          Desactivar una función no cancela lo que ya está en curso: ni las ofertas de la lista de
          espera ni los bonos ya comprados.
        </p>
        <SwitchField
          v-model="vouchers"
          label="Venta de bonos"
          description="Los clientes pueden comprar bonos prepagados. Los bonos ya comprados siguen vigentes aunque la desactive."
          @update:model-value="done = false"
        />
        <InlineNotice v-if="voucherNotice" title="Para vender bonos necesita una pasarela">
          Con la modalidad «Sin pago» no hay pasarela configurada y los clientes no podrían comprar.
          Elija depósito o pago total y una pasarela.
        </InlineNotice>
        <SwitchField
          v-model="waitlist"
          label="Lista de espera"
          description="Los clientes pueden anotarse cuando no hay espacio. Las ofertas en curso no se cancelan si la desactiva."
          @update:model-value="done = false"
        />
      </section>

      <div>
        <BaseButton type="submit" size="large" :loading="saving">Guardar cambios</BaseButton>
      </div>
    </form>
  </section>
</template>
