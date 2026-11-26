<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import BaseButton from '@/components/BaseButton.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import IncomeBlock from '@/components/IncomeBlock.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import StaffNavbar from '@/components/StaffNavbar.vue'
import TextField from '@/components/TextField.vue'
import { colones, formatShortDate, todayInCostaRica } from '@/composables/format'
import { useFormErrors } from '@/composables/useFormErrors'
import { useIncomeReport, type IncomeRange } from '@/composables/useIncomeReport'

// «Ingresos» of the owner (RF-25, lámina 37): what the shop charged online in a range of dates, net of refunds, the
// vouchers sold and, apart, the value of the services attended. Read only. The balance paid at the shop is not here
// (PA-10). The figures are the API's own; the only thing done to them is the format.
const route = useRoute()
const income = useIncomeReport()
const errors = useFormErrors<'from' | 'to'>({ desde: 'from', hasta: 'to' })

const shopId = computed(() => String(route.params.barberia))

// The API has no default range, so the screen opens on the current month: from the 1st to today, Costa Rica time.
const today = todayInCostaRica()
const from = ref(`${today.slice(0, 8)}01`)
const to = ref(today)
/** The range of the last request, so «Reintentar» asks for the same one. */
const asked = ref<IncomeRange>({ from: from.value, to: to.value })

const MINUS = '−'

/** «−₡13.500»: the refunds are shown as a deduction. Zero has no sign. */
function deduction(amount: number): string {
  return amount === 0 ? colones(0) : `${MINUS}${colones(Math.abs(amount))}`
}

const view = computed(() => {
  const data = income.report.value
  if (data === null) return null
  return {
    online: [
      { label: 'Cobrado en línea', value: colones(data.cobrado_en_linea.bruto) },
      { label: 'Reembolsos', value: deduction(data.cobrado_en_linea.reembolsado) },
      { label: 'Neto', value: colones(data.cobrado_en_linea.neto) },
    ],
    vouchers: [
      { label: 'Bonos vendidos', value: String(data.bonos_vendidos.cantidad) },
      { label: 'Total', value: colones(data.bonos_vendidos.total) },
    ],
    attended: [
      { label: 'Servicios atendidos', value: String(data.servicios_atendidos.cantidad) },
      { label: 'Valor atendido', value: colones(data.servicios_atendidos.total) },
    ],
  }
})

/** A range without money, vouchers or services: the API answers 200 with zeros. */
const isEmpty = computed(() => {
  const data = income.report.value
  if (data === null) return false
  return (
    data.cobrado_en_linea.bruto === 0 &&
    data.cobrado_en_linea.reembolsado === 0 &&
    data.cobrado_en_linea.neto === 0 &&
    data.bonos_vendidos.total === 0 &&
    data.bonos_vendidos.cantidad === 0 &&
    data.servicios_atendidos.total === 0 &&
    data.servicios_atendidos.cantidad === 0
  )
})

const loading = computed(() => income.status.value === 'loading')
const emptyText = computed(() => {
  const range = income.range.value
  if (range === null) return ''
  return `No hubo cobros, bonos vendidos ni servicios atendidos entre el ${formatShortDate(range.from)} y el ${formatShortDate(range.to)}. Pruebe con otro rango de fechas.`
})

async function request(range: IncomeRange): Promise<void> {
  asked.value = range
  await income.load(shopId.value, range)
  if (income.rejection.value !== null) {
    errors.setFromApiError(income.rejection.value)
    // A 422 about something else than a field is still said, under «Hasta».
    if (errors.generalError.value !== '') errors.setField('to', errors.generalError.value)
  }
}

function submit(): void {
  errors.clear()
  if (from.value === '') errors.setField('from', 'Elija la fecha inicial.')
  if (to.value === '') errors.setField('to', 'Elija la fecha final.')
  // The dates are «AAAA-MM-DD» strings, so comparing them as text is comparing the days.
  if (from.value !== '' && to.value !== '' && to.value < from.value) {
    errors.setField('to', 'La fecha final no puede ser anterior a la inicial.')
  }
  if (Object.keys(errors.fieldErrors.value).length > 0) return
  void request({ from: from.value, to: to.value })
}

onMounted(() => request(asked.value))
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <StaffNavbar />
    <main class="mx-auto w-full max-w-5xl flex-1 px-5 py-6 md:px-8">
      <h1 class="text-2xl">Ingresos</h1>
      <p class="mt-1 text-sm text-fg-muted">
        Lo que su barbería cobró en el rango de fechas, en hora de Costa Rica.
      </p>

      <form
        class="mt-6 grid grid-cols-1 items-start gap-4 sm:grid-cols-[1fr_1fr_auto]"
        novalidate
        @submit.prevent="submit"
      >
        <TextField
          v-model="from"
          type="date"
          label="Desde"
          :error="errors.fieldErrors.value.from"
          @input="errors.clearField('from')"
        />
        <TextField
          v-model="to"
          type="date"
          label="Hasta"
          :error="errors.fieldErrors.value.to"
          @input="errors.clearField('to')"
        />
        <div class="sm:pt-7">
          <BaseButton type="submit" :loading="loading">Ver ingresos</BaseButton>
        </div>
      </form>

      <!-- The result is announced when the range changes (aria-live); the loading state is a skeleton. -->
      <section
        aria-label="Resultado"
        aria-live="polite"
        class="mt-6 flex flex-col gap-4"
        :aria-busy="loading"
      >
        <SkeletonBlock
          v-if="income.status.value === 'idle' || (loading && income.report.value === null)"
          shape="block"
          :count="3"
          label="Cargando los ingresos"
        />
        <ErrorState
          v-else-if="income.status.value === 'error'"
          message="No pudimos cargar los ingresos"
          description="Revise su conexión e inténtelo de nuevo."
          @retry="request(asked)"
        />
        <EmptyState
          v-else-if="isEmpty"
          title="No hay ingresos en este rango."
          :description="emptyText"
        />
        <div v-else-if="view" class="grid grid-cols-1 gap-4 lg:grid-cols-3">
          <IncomeBlock
            title="Cobrado en línea, neto de reembolsos"
            :total="view.online[2]!.value"
            note="Depósitos y pagos totales de reservas, menos los reembolsos completados."
            :rows="view.online"
          />
          <IncomeBlock
            title="Bonos vendidos"
            :total="view.vouchers[1]!.value"
            note="Bonos pagados en el rango. No se suman al cobrado en línea de las reservas."
            :rows="view.vouchers"
          />
          <IncomeBlock
            eyebrow="Aparte"
            title="Valor atendido"
            :total="view.attended[1]!.value"
            note="Precio de los servicios marcados como atendidos en el rango. No es dinero cobrado."
            :rows="view.attended"
          />
        </div>
      </section>

      <p class="mt-6 text-sm text-fg-muted">
        El saldo que se cobra en el local no se incluye. Aquí solo entra lo que se cobró en línea.
      </p>
    </main>
  </div>
</template>
